<?php
declare(strict_types=1);

/**
 * SourceMapper
 *
 * Lädt die Quell-Schema-Beschreibung (z. B. mappings/afs.yml) und erzeugt
 * daraus performante SELECT-Statements inklusive konfigurierter Filter.
 * Die Klasse ist bewusst generisch gehalten, damit künftig weitere
 * Quell-Datenbanken angebunden werden können.
 */
class SourceMapper
{
    /** @var array<string,mixed> */
    private array $config;
    /** @var array<string,array<string,mixed>> */
    private array $tables;
    /** @var array<string,array<int,array<string,mixed>>> */
    private array $cache = [];

    public function __construct(array $config)
    {
        $this->config = $config;
        $tables = $config['tables'] ?? [];
        $this->tables = is_array($tables) ? $tables : [];
    }

    /**
     * Convenience factory für YAML-Dateien.
     */
    public static function fromFile(string $path): self
    {
        $data = YamlMappingLoader::load($path);
        return new self($data);
    }

    /**
     * Liefert alle definierten Tabellennamen.
     *
     * @return array<int,string>
     */
    public function listTables(): array
    {
        return array_keys($this->tables);
    }

    /**
     * Lädt Daten für eine konfigurierte Tabelle.
     *
     * @param MSSQL_Connection $connection
     * @param string $tableName Logischer Tabellenname aus der YAML-Konfiguration (z. B. "Artikel")
     * @param bool $useCache    Optional bereits geladene Daten wiederverwenden
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetch(MSSQL_Connection $connection, string $tableName, bool $useCache = true): array
    {
        if ($useCache && isset($this->cache[$tableName])) {
            return $this->cache[$tableName];
        }

        $tableConfig = $this->getTableConfig($tableName);
        $selectInfo = $this->buildSelect($tableConfig);
        $rows = $connection->fetchAll($selectInfo['sql'], $selectInfo['params']);

        if ($useCache) {
            $this->cache[$tableName] = $rows;
        }

        return $rows;
    }

    /**
     * Gibt die Tabellenkonfiguration zurück.
     *
     * @return array<string,mixed>
     */
    public function getTableConfig(string $tableName): array
    {
        $tableConfig = $this->tables[$tableName] ?? null;
        if (!is_array($tableConfig) || $tableConfig === []) {
            throw new RuntimeException(sprintf('Tabelle "%s" ist in der Quellkonfiguration nicht definiert.', $tableName));
        }
        return $tableConfig;
    }

    /**
     * Baut SELECT + WHERE aus der Tabellenkonfiguration.
     *
     * @param array<string,mixed> $tableConfig
     * @return array{sql:string,params:array<int,mixed>}
     */
    private function buildSelect(array $tableConfig): array
    {
        $source = $tableConfig['source'] ?? [];
        if (!is_array($source)) {
            throw new RuntimeException('Ungültige Tabellenkonfiguration: "source" fehlt oder ist kein Objekt.');
        }

        $table = $source['table'] ?? null;
        if (!is_string($table) || $table === '') {
            throw new RuntimeException('Ungültige Tabellenkonfiguration: "source.table" muss gesetzt sein.');
        }

        $fields = $tableConfig['fields'] ?? [];
        if (!is_array($fields) || $fields === []) {
            throw new RuntimeException('Ungültige Tabellenkonfiguration: "fields" muss eine nicht-leere Liste sein.');
        }

        $selectFields = [];
        foreach ($fields as $fieldDef) {
            if (is_string($fieldDef)) {
                $selectFields[] = $this->quoteIdentifier($fieldDef);
                continue;
            }
            if (is_array($fieldDef)) {
                // Unterstützt Kurzschreibweise: {alias: column}
                foreach ($fieldDef as $alias => $column) {
                    $selectFields[] = sprintf('%s AS %s', $this->quoteIdentifier((string)$column), $this->quoteIdentifier((string)$alias));
                }
                continue;
            }
        }

        if ($selectFields === []) {
            throw new RuntimeException('Keine gültigen Felddefinitionen für Tabelle ' . $table);
        }

        $sql = sprintf(
            'SELECT %s FROM %s',
            implode(', ', $selectFields),
            $this->quoteIdentifier($table)
        );

        $filters = $source['default_filter'] ?? [];
        $params = [];
        $where = $this->buildWhere($filters, $params);
        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        if (isset($source['order']) && is_string($source['order']) && $source['order'] !== '') {
            $sql .= ' ORDER BY ' . $this->buildOrderBy($source['order']);
        }

        return ['sql' => $sql, 'params' => $params];
    }

    /**
     * @param mixed $filters
     * @param array<int,mixed> $params
     */
    private function buildWhere($filters, array &$params): string
    {
        if (!is_array($filters) || $filters === []) {
            return '';
        }

        $parts = [];
        foreach ($filters as $column => $rule) {
            if (is_array($rule)) {
                foreach ($rule as $operator => $value) {
                    $parts[] = $this->buildFilterExpression((string)$column, (string)$operator, $value, $params);
                }
                continue;
            }
            // Fallback: direkter Vergleich (=)
            $parts[] = $this->buildFilterExpression((string)$column, 'eq', $rule, $params);
        }

        return implode(' AND ', array_filter($parts));
    }

    /**
     * @param mixed $value
     * @param array<int,mixed> $params
     */
    private function buildFilterExpression(string $column, string $operator, $value, array &$params): string
    {
        $quotedColumn = $this->quoteIdentifier($column);
        $op = strtolower($operator);
        switch ($op) {
            case 'eq':
                $params[] = $value;
                return sprintf('%s = ?', $quotedColumn);
            case 'ne':
                $params[] = $value;
                return sprintf('%s <> ?', $quotedColumn);
            case 'lt':
                $params[] = $value;
                return sprintf('%s < ?', $quotedColumn);
            case 'lte':
            case 'le':
                $params[] = $value;
                return sprintf('%s <= ?', $quotedColumn);
            case 'gt':
                $params[] = $value;
                return sprintf('%s > ?', $quotedColumn);
            case 'gte':
            case 'ge':
                $params[] = $value;
                return sprintf('%s >= ?', $quotedColumn);
            case 'like':
                $params[] = $value;
                return sprintf('%s LIKE ?', $quotedColumn);
            case 'not_like':
                $params[] = $value;
                return sprintf('%s NOT LIKE ?', $quotedColumn);
            case 'in':
                if (!is_array($value) || $value === []) {
                    return '1 = 0'; // leere IN-Liste -> niemals wahr
                }
                $placeholders = array_fill(0, count($value), '?');
                foreach ($value as $item) {
                    $params[] = $item;
                }
                return sprintf('%s IN (%s)', $quotedColumn, implode(', ', $placeholders));
            case 'not_in':
                if (!is_array($value) || $value === []) {
                    return '1 = 1';
                }
                $placeholders = array_fill(0, count($value), '?');
                foreach ($value as $item) {
                    $params[] = $item;
                }
                return sprintf('%s NOT IN (%s)', $quotedColumn, implode(', ', $placeholders));
            case 'between':
                if (!is_array($value) || count($value) !== 2) {
                    throw new RuntimeException('between-Filter erwartet genau zwei Werte.');
                }
                $params[] = $value[0];
                $params[] = $value[1];
                return sprintf('%s BETWEEN ? AND ?', $quotedColumn);
            case 'not_null':
                return sprintf('%s IS NOT NULL', $quotedColumn);
            case 'is_null':
                return sprintf('%s IS NULL', $quotedColumn);
            default:
                throw new RuntimeException(sprintf('Unbekannter Filter-Operator "%s" für Spalte %s', $operator, $column));
        }
    }

    private function buildOrderBy(string $order): string
    {
        $segments = array_map('trim', explode(',', $order));
        $quoted = [];
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $segment);
            $column = $parts[0] ?? '';
            if ($column === '') {
                continue;
            }
            $direction = strtoupper($parts[1] ?? '');
            if ($direction !== 'ASC' && $direction !== 'DESC') {
                $direction = '';
            }
            $quoted[] = trim($this->quoteIdentifier($column) . ' ' . $direction);
        }
        return implode(', ', $quoted);
    }

    private function quoteIdentifier(string $identifier): string
    {
        $parts = array_map('trim', explode('.', $identifier));
        $quoted = [];
        foreach ($parts as $part) {
            if ($part === '*') {
                $quoted[] = '*';
                continue;
            }
            $quoted[] = '[' . str_replace(']', ']]', $part) . ']';
        }
        return implode('.', $quoted);
    }
}
