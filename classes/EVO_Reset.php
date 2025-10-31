<?php
declare(strict_types=1);

/**
 * Hilfsklasse zum Leeren der EVO-Zwischendatenbank.
 * Löscht alle Nutzertabellen (außer SQLite-Systemtabellen) und setzt Auto-Increment-Sequenzen zurück.
 */
final class EVO_Reset
{
    /**
     * Löscht den Inhalt aller Anwendungs-Tabellen und gibt die Anzahl der entfernten Zeilen pro Tabelle zurück.
     *
     * @return array<string,int>
     */
    public static function clear(PDO $pdo): array
    {
        $tables = self::listUserTables($pdo);
        if ($tables === []) {
            return [];
        }

        $foreignKeysPreviouslyEnabled = self::getForeignKeysState($pdo);
        $result = [];

        $pdo->exec('PRAGMA foreign_keys = OFF');

        $inTransaction = $pdo->inTransaction();
        try {
            if (!$inTransaction) {
                $pdo->beginTransaction();
            }

            foreach ($tables as $table) {
                $quoted = self::quoteIdentifier($table);
                $countStmt = $pdo->query(sprintf('SELECT COUNT(*) FROM %s', $quoted));
                $count = $countStmt !== false ? (int)$countStmt->fetchColumn() : 0;
                $pdo->exec(sprintf('DELETE FROM %s', $quoted));
                $result[$table] = $count;
            }

            if (self::hasSqliteSequence($pdo)) {
                $pdo->exec('DELETE FROM sqlite_sequence');
            }

            if (!$inTransaction) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if (!$inTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::restoreForeignKeysState($pdo, $foreignKeysPreviouslyEnabled);
        }

        return $result;
    }

    private static function listUserTables(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        if ($stmt === false) {
            return [];
        }
        /** @var array<int,string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter(array_map('strval', $names)));
    }

    private static function hasSqliteSequence(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function getForeignKeysState(PDO $pdo): bool
    {
        $stmt = $pdo->query('PRAGMA foreign_keys');
        if ($stmt === false) {
            return true;
        }
        $value = $stmt->fetchColumn();
        return (int)$value === 1;
    }

    private static function restoreForeignKeysState(PDO $pdo, bool $enabled): void
    {
        $pdo->exec('PRAGMA foreign_keys = ' . ($enabled ? 'ON' : 'OFF'));
    }
}
