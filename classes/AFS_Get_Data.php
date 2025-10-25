<?php
/**
 * AFS – Datenlese- und Nachbearbeitungs-Klasse für AFS-Manager (MSSQL)
 *
 * Liest die Tabellen "Artikel", "Warengruppe" und "Dokument" gemäß Vorgaben
 * aus der YAML-Konfiguration (source_afs.yml) und liefert die Ergebnisse als Arrays zurück.
 * Nutzt TransformRegistry für Transformationen (trim, basename, rtf_to_html, etc.).
 *
 * Voraussetzungen:
 *  - Eine vorhandene MSSQL.php mit einer Klasse "MSSQL" (oder kompatibel),
 *    die mindestens eine der Methoden "fetchAll($sql, $params = [])" oder
 *    "query($sql, $params = [])" bereitstellt und ein Array von Zeilen
 *    (assoziative Arrays) zurückliefert.
 *  - YAML-Extension für PHP
 *  - source_afs.yml Konfigurationsdatei in /mappings
 *
 * Beispielverwendung:
 *  require __DIR__ . '/MSSQL.php';
 *  $db = new MSSQL($host, $database, $user, $pass);
 *  $afs = new AFS_Get_Data($db);
 *  $artikel    = $afs->getArtikel();
 *  $gruppen    = $afs->getWarengruppen();
 *  $dokumente  = $afs->getDokumente();
 */

require_once __DIR__ . '/MSSQL.php';

class AFS_Get_Data
{
    /** @var object */
    private $db;

    /** @var AFS_MappingConfig */
    private $config;

    /** @var \Mapping\TransformRegistry */
    private $transformRegistry;

    /**
     * @param object $db Instanz der MSSQL-Datenbankklasse (kompatibel zu fetchAll/query)
     * @param string|null $configPath Optional path to YAML config file
     */
    public function __construct($db, ?string $configPath = null)
    {
        if (!is_object($db)) {
            throw new AFS_ValidationException('AFS: $db muss ein Objekt sein.');
        }
        $this->db = $db;

        // Load configuration
        if ($configPath === null) {
            $configPath = __DIR__ . '/../mappings/source_afs.yml';
        }
        $this->config = new AFS_MappingConfig($configPath);

        // Initialize transform registry
        $this->transformRegistry = new \Mapping\TransformRegistry();
    }

    /**
     * Liefert Artikel gemäß YAML-Konfiguration
     *
     * @return array<int, array<string, mixed>>
     */
    public function getArtikel(): array
    {
        $sql = $this->config->buildSelectQuery('Artikel');
        $rows = $this->run($sql);
        return array_map([$this, 'normalizeArtikelRow'], $rows);
    }

    /**
     * Liefert Warengruppen gemäß YAML-Konfiguration
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarengruppen(): array
    {
        $sql = $this->config->buildSelectQuery('Warengruppe');
        $rows = $this->run($sql);
        return array_map([$this, 'normalizeWarengruppeRow'], $rows);
    }

    /**
     * Liefert Dokumente gemäß YAML-Konfiguration
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDokumente(): array
    {
        $sql = $this->config->buildSelectQuery('Dokumente');
        $rows = $this->run($sql);
        return array_map([$this, 'normalizeDokumentRow'], $rows);
    }

    /**
     * Helper: generische Ausführung, verträgt unterschiedliche MSSQL-Wrapper.
     *
     * @param string $sql
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    private function run(string $sql, array $params = []): array
    {
        // Bevorzugt fetchAll, fallback auf query
        if (method_exists($this->db, 'fetchAll')) {
            $rows = $this->db->fetchAll($sql, $params);
        } elseif (method_exists($this->db, 'query')) {
            $rows = $this->db->query($sql, $params);
        } else {
            throw new AFS_ValidationException('AFS: Die angegebene DB-Klasse unterstützt weder fetchAll() noch query().');
        }

        if (!is_array($rows)) {
            throw new AFS_DatabaseException('AFS: DB-Rückgabe ist ungültig (erwarte Array von Zeilen).');
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function normalizeArtikelRow(array $row): array
    {
        $out = $row;
        $fields = $this->config->getFields('Artikel');

        // Apply type conversions and transformations based on config
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!array_key_exists($fieldName, $out)) {
                continue;
            }

            $type = $fieldConfig['type'] ?? 'string';
            $transform = $fieldConfig['transform'] ?? null;

            // Apply transformation first if specified
            if ($transform !== null) {
                $out[$fieldName] = $this->transformRegistry->apply($transform, $out[$fieldName]);
            }

            // Apply type conversion
            $this->applyTypeConversion($out, $fieldName, $type);
        }

        // Special handling for datetime fields
        if (array_key_exists('last_update', $out) && !empty($out['last_update'])) {
            $ts = strtotime((string)$out['last_update']);
            if ($ts !== false) {
                $out['last_update'] = date('c', $ts);
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function normalizeWarengruppeRow(array $row): array
    {
        $out = $row;
        $fields = $this->config->getFields('Warengruppe');

        // Apply type conversions and transformations based on config
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!array_key_exists($fieldName, $out)) {
                continue;
            }

            $type = $fieldConfig['type'] ?? 'string';
            $transform = $fieldConfig['transform'] ?? null;

            // Apply transformation first if specified
            if ($transform !== null) {
                $out[$fieldName] = $this->transformRegistry->apply($transform, $out[$fieldName]);
            }

            // Apply type conversion
            $this->applyTypeConversion($out, $fieldName, $type);
        }

        // Add AFS_ID for backward compatibility
        if (isset($out['Warengruppe'])) {
            $out['AFS_ID'] = (int)$out['Warengruppe'];
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function normalizeDokumentRow(array $row): array
    {
        $out = $row;
        $fields = $this->config->getFields('Dokumente');

        // Apply type conversions and transformations based on config
        foreach ($fields as $fieldName => $fieldConfig) {
            if (!array_key_exists($fieldName, $out)) {
                continue;
            }

            $type = $fieldConfig['type'] ?? 'string';
            $transform = $fieldConfig['transform'] ?? null;

            // Apply transformation first if specified
            if ($transform !== null) {
                $out[$fieldName] = $this->transformRegistry->apply($transform, $out[$fieldName]);
            }

            // Apply type conversion
            $this->applyTypeConversion($out, $fieldName, $type);
        }

        return $out;
    }

    /**
     * Apply type conversion to a field
     * 
     * @param array<string, mixed> $row
     * @param string $key
     * @param string $type
     */
    private function applyTypeConversion(array &$row, string $key, string $type): void
    {
        if (!array_key_exists($key, $row)) {
            return;
        }

        switch ($type) {
            case 'integer':
                $this->toInt($row, $key);
                break;
            case 'float':
            case 'decimal':
                $this->toFloat($row, $key);
                break;
            case 'boolean':
                $this->toBool($row, $key);
                break;
            case 'datetime':
                // Datetime is handled separately in normalize methods
                break;
            case 'string':
            default:
                // No conversion needed for strings
                break;
        }
    }

    /** @param array<string, mixed> $row */
    private function toInt(array &$row, string $key): void
    {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $row[$key] = (int)$row[$key];
        }
    }

    /** @param array<string, mixed> $row */
    private function toFloat(array &$row, string $key): void
    {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            $row[$key] = (float)str_replace(',', '.', (string)$row[$key]);
        }
    }

    /** @param array<string, mixed> $row */
    private function toBool(array &$row, string $key): void
    {
        if (!array_key_exists($key, $row)) {
            return;
        }
        $val = $row[$key];
        if (is_bool($val)) {
            return; // schon ok
        }
        // Häufige SQL-Server-Repräsentationen (bit, tinyint, Strings)
        $truthy = ['1', 'true', 'TRUE', 'ja', 'JA', 'yes', 'YES', 'y', 'Y'];
        $falsy  = ['0', 'false', 'FALSE', 'nein', 'NEIN', 'no', 'NO', 'n', 'N'];
        if (is_numeric($val)) {
            $row[$key] = ((int)$val) === 1;
        } elseif (is_string($val)) {
            if (in_array($val, $truthy, true)) {
                $row[$key] = true;
            } elseif (in_array($val, $falsy, true)) {
                $row[$key] = false;
            }
        }
    }
}
