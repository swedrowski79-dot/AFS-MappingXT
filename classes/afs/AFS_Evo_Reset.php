<?php
declare(strict_types=1);

class AFS_Evo_Reset
{
    /**
     * Leert alle relevanten Tabellen in der EVO-SQLite-Datenbank.
     *
     * @return array<string,int> Anzahl der gelöschten Zeilen je Tabelle.
     */
    public static function clear(PDO $pdo): array
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $tables = [
            'Artikel_Bilder',
            'Artikel_Dokumente',
            'Attrib_Artikel',
            'Artikel',
            'Bilder',
            'Dokumente',
            'Attribute',
            'category',
        ];

        $affectedRows = [];

        $pdo->beginTransaction();
        try {
            foreach ($tables as $table) {
                $statement = sprintf('DELETE FROM %s', self::quoteIdent($table));
                $count = $pdo->exec($statement);
                if ($count === false) {
                    throw new AFS_DatabaseException('Fehler beim Leeren der Tabelle ' . $table);
                }
                $affectedRows[$table] = $count;
            }

            // Autoincrement-Zähler zurücksetzen (falls vorhanden)
            if (self::hasSqliteSequence($pdo)) {
                $placeholders = implode(',', array_fill(0, count($tables), '?'));
                $resetStmt = $pdo->prepare("DELETE FROM sqlite_sequence WHERE name IN ({$placeholders})");
                $resetStmt->execute($tables);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $affectedRows;
    }

    private static function quoteIdent(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    private static function hasSqliteSequence(PDO $pdo): bool
    {
        $stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'");
        return $stmt !== false && $stmt->fetchColumn() !== false;
    }
}

