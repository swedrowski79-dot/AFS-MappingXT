<?php
/**
 * AFS – Datenlese- und Nachbearbeitungs-Klasse für AFS-Manager (MSSQL)
 *
 * Liest die Tabellen "Artikel", "Warengruppe" und "Dokument" gemäß Vorgaben
 * und liefert die Ergebnisse als Arrays zurück. Zusätzlich: Pfadkürzung,
 * einfacher RTF→Text/HTML-Fallback, HTML-Entfernung – analog zu deiner alten Hilfsklasse.
 *
 * Voraussetzungen:
 *  - Eine vorhandene MSSQL.php mit einer Klasse "MSSQL" (oder kompatibel),
 *    die mindestens eine der Methoden "fetchAll($sql, $params = [])" oder
 *    "query($sql, $params = [])" bereitstellt und ein Array von Zeilen
 *    (assoziative Arrays) zurückliefert.
 *
 * Beispielverwendung:
 *  require __DIR__ . '/MSSQL.php';
 *  $db = new MSSQL($host, $database, $user, $pass);
 *  $afs = new AFS($db);
 *  $artikel    = $afs->getArtikel();
 *  $gruppen    = $afs->getWarengruppen();
 *  $dokumente  = $afs->getDokumente();
 */

require_once __DIR__ . '/MSSQL.php';

class AFS_Get_Data
{
    /** @var object */
    private $db;

    /**
     * @param object $db Instanz der MSSQL-Datenbankklasse (kompatibel zu fetchAll/query)
     */
    public function __construct($db)
    {
        if (!is_object($db)) {
            throw new InvalidArgumentException('AFS: $db muss ein Objekt sein.');
        }
        $this->db = $db;
    }

    /**
     * Liefert Artikel gemäß WHERE: Mandant = 1 AND Art < 255 AND Artikelnummer IS NOT NULL
     * und nur die geforderten Spalten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getArtikel(): array
    {
        $sql = "SELECT
            [Artikel],
            [Art],
            [Artikelnummer],
            [Bezeichnung],
            [EANNummer],
            [Bestand],
            [Bild1], [Bild2], [Bild3], [Bild4], [Bild5], [Bild6], [Bild7], [Bild8], [Bild9], [Bild10],
            [VK3]            AS [Preis],
            [Warengruppe],
            [Umsatzsteuer],
            [Zusatzfeld01]   AS [Mindestmenge],
            [Zusatzfeld03]   AS [Attribname1],
            [Zusatzfeld04]   AS [Attribname2],
            [Zusatzfeld05]   AS [Attribname3],
            [Zusatzfeld06]   AS [Attribname4],
            [Zusatzfeld15]   AS [Attribvalue1],
            [Zusatzfeld16]   AS [Attribvalue2],
            [Zusatzfeld17]   AS [Attribvalue3],
            [Zusatzfeld18]   AS [Attribvalue4],
            [Zusatzfeld07]   AS [Master],
            [Bruttogewicht],
            [Internet]       AS [Online],
            [Einheit],
            [Langtext],
            [Werbetext1],
            [Bemerkung],
            [Hinweis],
            [Update]         AS [last_update]
        FROM [Artikel]
        WHERE [Mandant] = 1 AND [Art] < 255 AND [Artikelnummer] IS NOT NULL AND [Internet] = 1";

        $rows = $this->run($sql);
        return array_map([$this, 'normalizeArtikelRow'], $rows);
    }

    /**
     * Liefert Warengruppen gemäß WHERE: Mandant = 1
     * und nur die geforderten Spalten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getWarengruppen(): array
    {
        $sql = "SELECT
            [Warengruppe],
            [Art],
            [Anhang] AS [Parent],
            [Ebene],
            [Bezeichnung],
            [Internet] AS [Online],
            [Bild],
            [Bild_gross],
            [Beschreibung]
        FROM [Warengruppe]
        WHERE [Mandant] = 1 AND [Internet] = 1";

        $rows = $this->run($sql);
        return array_map([$this, 'normalizeWarengruppeRow'], $rows);
    }

    /**
     * Liefert Dokumente gemäß WHERE: Artikel > 0
     * und nur die geforderten Spalten.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDokumente(): array
    {
        $sql = "SELECT
            [Zaehler],
            [Artikel],
            [Dateiname],
            [Titel],
            [Art]
        FROM [Dokument]
        WHERE [Artikel] > 0";

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
            throw new RuntimeException('AFS: Die angegebene DB-Klasse unterstützt weder fetchAll() noch query().');
        }

        if (!is_array($rows)) {
            throw new RuntimeException('AFS: DB-Rückgabe ist ungültig (erwarte Array von Zeilen).');
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function normalizeArtikelRow(array $row): array
    {
        $out = $row;

        // Typsicherungen / Normalisierungen
        $this->toInt($out, 'Artikel');
        $this->toInt($out, 'Art');
        $this->toInt($out, 'Bestand');
        $this->toInt($out, 'Warengruppe');
        $this->toFloat($out, 'Preis');
        $this->toFloat($out, 'Umsatzsteuer');
        $this->toFloat($out, 'Bruttogewicht');

        $this->toBool($out, 'Online'); // Webshop ja/nein -> bool

        // Datumsfeld last_update (ehem. Update) in ISO 8601 normalisieren, falls vorhanden
        if (array_key_exists('last_update', $out) && !empty($out['last_update'])) {
            $ts = strtotime((string)$out['last_update']);
            if ($ts !== false) {
                $out['last_update'] = date('c', $ts);
            }
        }

        // Nachbearbeitung: Pfade kürzen, RTF/HTML behandeln
        foreach (range(1, 10) as $i) {
            $this->replacePath($out, 'Bild' . $i);
        }
        $this->convertRtfToHtml($out, 'Langtext');
        $this->removeHtml($out, 'Bemerkung');
        $this->removeHtml($out, 'Hinweis');

        return $out;
    }

    /** @param array<string, mixed> $row */
    private function normalizeWarengruppeRow(array $row): array
    {
        $out = $row;
        $this->toInt($out, 'Warengruppe');
        $this->toInt($out, 'Art');
        $this->toInt($out, 'Ebene');
        $this->toInt($out, 'Parent');
        $this->toBool($out, 'Online');

        if (isset($out['Warengruppe'])) {
            $out['AFS_ID'] = (int)$out['Warengruppe'];
        }

        // Pfade vereinheitlichen/kürzen
        $this->replacePath($out, 'Bild');
        $this->replacePath($out, 'Bild_gross');
        return $out;
    }

    /** @param array<string, mixed> $row */
    private function normalizeDokumentRow(array $row): array
    {
        $out = $row;
        $this->toInt($out, 'Zaehler');
        $this->toInt($out, 'Artikel');
        $this->toInt($out, 'Art');

        // Pfad vereinheitlichen/kürzen
        $this->replacePath($out, 'Dateiname');
        if (isset($out['Titel'])) {
            $out['Titel'] = $this->normalizeTitle((string)$out['Titel']);
        }
        return $out;
    }

    private function normalizeTitle(string $title): string
    {
        $trimmed = trim($title);
        if ($trimmed === '') {
            return '';
        }
        $standardized = strtr($trimmed, ['\\' => '/', '//' => '/']);
        return basename($standardized);
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

    // -------------------- Hilfsfunktionen für Nachbearbeitung --------------------

    /** Pfade normalisieren (\\ → /) und nur den Dateinamen behalten */
    private function replacePath(array &$row, string $key): void
    {
        try {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                return;
            }
            $val = (string)$row[$key];
            // Backslashes aus Windows-Pfaden vereinheitlichen
            $val = strtr($val, array('\\\\' => '/', '\\' => '/'));
            // Nur Dateiname behalten
            $row[$key] = basename($val);
        } catch (\Throwable $e) { /* bewusst ignoriert */ }
    }

    /**
     * Sehr einfacher RTF→HTML/Text Fallback (weil Convert::RtfToHtml nicht mehr vorhanden ist).
     * Entfernt grob RTF-Steuerzeichen. Liefert Plaintext-ähnlichen Inhalt.
     */
    private function convertRtfToHtml(array &$row, string $key): void
    {
        try {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                return;
            }
            $val = (string)$row[$key];

            if (strpos($val, '{\\rtf') !== false) {
                // RTF-Steuerwörter (z. B. \par, \tab, \b0, \fs22 ...) grob entfernen
                $val = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $val);
                // Geschweifte Klammern und Backslashes entfernen
                $val = str_replace(array('{', '}', '\\'), '', $val);
                // Mehrfache Leerzeichen reduzieren
                $val = trim(preg_replace('/\s+/', ' ', $val));
            }

            $row[$key] = $val; // Ergebnis ist eher Plaintext
        } catch (\Throwable $e) { /* bewusst ignoriert */ }
    }

    /** RTF→Text; danach HTML-Tags entfernen (für Bemerkung/Hinweis) */
    private function removeHtml(array &$row, string $key): void
    {
        try {
            if (!array_key_exists($key, $row) || $row[$key] === null || $row[$key] === '') {
                return;
            }
            $val = (string)$row[$key];

            if (strpos($val, '{\\rtf') !== false) {
                $val = preg_replace('/\\\\[a-zA-Z]+-?\d* ?/', ' ', $val);
                $val = str_replace(array('{', '}', '\\'), '', $val);
                $val = trim(preg_replace('/\s+/', ' ', $val));
            }

            $row[$key] = strip_tags($val);
        } catch (\Throwable $e) { /* bewusst ignoriert */ }
    }
}
