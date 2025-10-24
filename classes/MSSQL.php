<?php
declare(strict_types=1);

class MSSQL
{
    private string $serverName;
    private array  $connectionOptions;
    /** @var resource|null */
    private $conn = null;
    private int $queryTimeout;

    public function __construct(
        string $serverName,
        string $username,
        string $password,
        string $database,
        array $options = []
    ) {
        $this->serverName = $serverName;

        // Defaults + Overrides
        $this->connectionOptions = [
            'Database'               => $database,
            'UID'                    => $username,
            'PWD'                    => $password,
            'CharacterSet'           => 'UTF-8',
            'LoginTimeout'           => $options['login_timeout'] ?? 10,
            // ODBC 18: Encrypt=Yes default → hier explizit steuerbar:
            'Encrypt'                => ($options['encrypt'] ?? true) ? 1 : 0,
            'TrustServerCertificate' => ($options['trust_server_certificate'] ?? false) ? 1 : 0,
            // Gibt Datumswerte als Strings zurück (einfacher für JSON/Weiterverarbeitung)
            'ReturnDatesAsStrings'   => $options['return_dates_as_strings'] ?? true,
            // Connection-Pooling (standardmäßig an)
            'ConnectionPooling'      => $options['connection_pooling'] ?? 1,
        ];

        $this->queryTimeout = (int)($options['query_timeout'] ?? 30);

        $this->connect();
    }

    private function connect(): void
    {
        $this->conn = sqlsrv_connect($this->serverName, $this->connectionOptions);
        if (!$this->conn) {
            throw new RuntimeException($this->formatErrors(sqlsrv_errors()));
        }
    }

    /** Sichere Spalten-/Tabellenbezeichner: [name], Schema.Table wird unterstützt. */
    private function qIdent(string $ident): string
    {
        // Split an Punkten, jeden Teil in [..] packen, ] escapen → ]]
        $parts = array_map('trim', explode('.', $ident));
        $parts = array_map(function ($p) {
            if ($p === '*') return '*'; // falls bewusst SELECT *
            return '[' . str_replace(']', ']]', $p) . ']';
        }, $parts);
        return implode('.', $parts);
    }

    /** SELECT-Helfer; Felder/Table als Strings ODER Arrays (empfohlen) */
    public function select(array|string $fields, string $table, string $where = '', array $params = [], string $orderBy = ''): array
    {
        [$sql, $params] = $this->buildSelect($fields, $table, $where, $params, $orderBy);
        return $this->fetchAllAssoc($sql, $params);
    }

    /** Paginierte Auswahl: ORDER BY ist Pflicht, sonst THROW (Offset braucht Sortierung) */
    public function selectPaged(
        array|string $fields,
        string $table,
        string $where = '',
        array $params = [],
        string $orderBy = '',
        int $offset = 0,
        int $limit = 100
    ): array {
        if ($orderBy === '') {
            throw new InvalidArgumentException('ORDER BY ist für OFFSET/FETCH erforderlich.');
        }
        [$sql, $params] = $this->buildSelect($fields, $table, $where, $params, $orderBy);
        $sql .= ' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
        $params[] = $offset;
        $params[] = $limit;
        return $this->fetchAllAssoc($sql, $params);
    }

    /** Zähle Zeilen für dieselben Bedingungen */
    public function count(string $table, string $where = '', array $params = []): int
    {
        $tableQ = $this->qIdent($table);
        $sql = "SELECT COUNT(*) AS c FROM {$tableQ}" . ($where !== '' ? " WHERE {$where}" : '');
        $row = $this->fetchOneAssoc($sql, $params);
        return (int)($row['c'] ?? 0);
    }

    /** Allzweck-Query (parametrisiert), liefert Statement-Resource zurück */
    public function query(string $sql, array $params = [])
    {
        $options = ['Scrollable' => SQLSRV_CURSOR_FORWARD];
        if ($this->queryTimeout > 0) {
            $options['QueryTimeout'] = $this->queryTimeout;
        }
        $stmt = sqlsrv_query($this->conn, $sql, $params, $options);
        if ($stmt === false) {
            throw new RuntimeException($this->formatErrors(sqlsrv_errors()));
        }
        return $stmt;
    }

    /** Einfache Fetch-Helfer */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->fetchAllAssoc($sql, $params);
    }

    public function fetchAllAssoc(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $rows = [];
        while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
            $rows[] = $this->normalizeRow($row);
        }
        sqlsrv_free_stmt($stmt);
        return $rows;
    }

    public function fetchOneAssoc(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: [];
        sqlsrv_free_stmt($stmt);
        return $this->normalizeRow($row);
    }

    /** Streaming/Generator – nützlich bei großen Resultsets */
    public function fetchGenerator(string $sql, array $params = []): Generator
    {
        $stmt = $this->query($sql, $params);
        try {
            while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
                yield $this->normalizeRow($row);
            }
        } finally {
            sqlsrv_free_stmt($stmt);
        }
    }

    /** Nur einen Skalar (erste Spalte der ersten Zeile) */
    public function scalar(string $sql, array $params = [])
    {
        $stmt = $this->query($sql, $params);
        $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_NUMERIC);
        sqlsrv_free_stmt($stmt);
        return $row[0] ?? null;
    }

    /** Baue SELECT-Statement sicher zusammen */
    private function buildSelect(array|string $fields, string $table, string $where, array $params, string $orderBy): array
    {
        // Felder
        if (is_string($fields)) {
            // "id, name" → in Array splitten (robustheit)
            $fields = array_map('trim', explode(',', $fields));
        }
        // Quote alle Felder außer *
        $fieldList = array_map(fn($f) => $f === '*' ? '*' : $this->qIdent($f), $fields);
        $fieldsSql = implode(', ', $fieldList);

        // Tabelle
        $tableQ = $this->qIdent($table);

        $sql = "SELECT {$fieldsSql} FROM {$tableQ}";
        if ($where !== '') {
            $sql .= " WHERE {$where}";
        }
        if ($orderBy !== '') {
            // einfache Absicherung: einzelne/kommagetrennte Spalten erlauben
            $orderParts = array_map('trim', explode(',', $orderBy));
            $orderQuoted = [];
            foreach ($orderParts as $p) {
                // erlaubte Muster: "Col ASC|DESC"
                if (preg_match('/^([A-Za-z0-9_\.]+)\s*(ASC|DESC)?$/i', $p, $m)) {
                    $orderQuoted[] = $this->qIdent($m[1]) . (isset($m[2]) ? ' ' . strtoupper($m[2]) : '');
                } else {
                    throw new InvalidArgumentException("Ungültiger ORDER BY Ausdruck: {$p}");
                }
            }
            $sql .= ' ORDER BY ' . implode(', ', $orderQuoted);
        }

        return [$sql, $params];
    }

    /** Normalisierung (z. B. DateTime-Objekte → Strings) */
    private function normalizeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if ($v instanceof DateTimeInterface) {
                $row[$k] = $v->format('c'); // ISO-8601
            }
        }
        return $row;
    }

    private function formatErrors($errors): string
    {
        if (!is_array($errors)) return 'Unbekannter SQLSRV-Fehler.';
        $msgs = [];
        foreach ($errors as $e) {
            $msgs[] = sprintf('[%s] %s (SQLSTATE: %s)', $e['code'] ?? '?', $e['message'] ?? '??', $e['SQLSTATE'] ?? '??');
        }
        return implode(' | ', $msgs);
    }

    public function close(): void
    {
        if (is_resource($this->conn)) {
            sqlsrv_close($this->conn);
        }
        $this->conn = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
