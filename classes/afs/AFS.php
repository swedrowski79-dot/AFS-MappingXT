<?php

/**
 * AFS – Aggregatorklasse für AFS-Daten
 *
 * Diese Klasse hält die Arrays:
 *  - $Artikel
 *  - $Warengruppe
 *  - $Dokumente
 *  - $Bilder
 *  - $Attribute
 *  - $artikel_neu
 *  - $warengruppe_neu
 *  - $artikel_attribute
 *  - $artikel_bilder
 *  - $warengruppe_bilder
 *
 * Sie befüllt sich aus einer Daten-Quelle, die die Methoden
 *  getArtikel(), getWarengruppen(), getDokumente() anbietet (z. B. deine hochgeladene AFS-get-data.php,
 *  die bereits eine Klasse AFS mit genau diesen Methoden enthält).
 *
 * Beispiel:
 *   $src = new AFS_Get_Data($mssql); // aus AFS-get-data.php
 *   $agg = new AFS($src);
 *
 * Hinweis: Diese Klasse aggregiert die Datenquelle (AFS_Get_Data) in Arrays.
 */
class AFS
{
    // === Arrays ===
    public array $Artikel = [];
    public array $Warengruppe = [];
    public array $Dokumente = [];
    public array $Bilder = [];
    public array $Attribute = [];
    private array $config;

    /**
     * @param object $source Ein Objekt mit getArtikel(), getWarengruppen(), getDokumente()
     */
    public function __construct(object $source, array $config = [])
    {
        $this->config = $config;
        // 1) Artikel füllen
        $this->loadArtikel($source);
        // 2) Warengruppen füllen
        $this->loadWarengruppe($source);
        // 3) Dokumente füllen
        $this->loadDokumente($source);
        $this->collectBilder();
        $this->collectAttributeNamen();
        $this->enrichMetadata();
    }

    private function loadArtikel(object $source): void
    {
        if (!method_exists($source, 'getArtikel')) {
            throw new AFS_ValidationException('Quelle bietet keine getArtikel()-Methode.');
        }
        $this->Artikel = $source->getArtikel();
    }

    private function loadWarengruppe(object $source): void
    {
        if (!method_exists($source, 'getWarengruppen')) {
            throw new AFS_ValidationException('Quelle bietet keine getWarengruppen()-Methode.');
        }
        $this->Warengruppe = $source->getWarengruppen();
    }

    private function loadDokumente(object $source): void
    {
        if (!method_exists($source, 'getDokumente')) {
            throw new AFS_ValidationException('Quelle bietet keine getDokumente()-Methode.');
        }
        $this->Dokumente = $source->getDokumente();
    }

    /**
     * Sammelt alle Bild-Dateinamen aus $Artikel (Bild1..Bild10)
     * und $Warengruppe (Bild, Bild_gross) und speichert sie dedupliziert in $Bilder.
     */
    public function collectBilder(): void
    {
        $bilder = [];

        // Aus Artikel: Bild1..Bild10
        foreach ($this->Artikel as $row) {
            if (!is_array($row)) { continue; }
            for ($i = 1; $i <= 10; $i++) {
                $key = 'Bild' . $i;
                if (array_key_exists($key, $row) && !empty($row[$key])) {
                    $bilder[] = (string)$row[$key];
                }
            }
        }

        // Aus Warengruppen: Bild, Bild_gross
        foreach ($this->Warengruppe as $row) {
            if (!is_array($row)) { continue; }
            foreach (['Bild', 'Bild_gross'] as $key) {
                if (array_key_exists($key, $row) && !empty($row[$key])) {
                    $bilder[] = (string)$row[$key];
                }
            }
        }

        // Leere entfernen und Duplikate vermeiden
        $bilder = array_filter($bilder, function ($v) {
            return $v !== null && $v !== '';
        });
        $bilder = array_values(array_unique($bilder));

        $this->Bilder = $bilder;
    }

    /**
     * Sammelt alle Attribut-Namen aus $Artikel (Attribname1..Attribname4)
     * und speichert sie dedupliziert in $Attribute.
     */
    public function collectAttributeNamen(): void
    {
        $namen = [];

        foreach ($this->Artikel as $row) {
            if (!is_array($row)) { continue; }
            for ($i = 1; $i <= 4; $i++) {
                $key = 'Attribname' . $i;
                if (array_key_exists($key, $row) && !empty($row[$key])) {
                    $namen[] = trim((string)$row[$key]);
                }
            }
        }

        // Leere entfernen und Duplikate vermeiden
        $namen = array_filter($namen, function ($v) {
            return $v !== null && $v !== '';
        });
        $namen = array_values(array_unique($namen));

        $this->Attribute = $namen;
    }

    private function enrichMetadata(): void
    {
        $paths = $this->config['paths']['metadata'] ?? null;
        if (!is_array($paths)) {
            return;
        }
        if (!class_exists(AFS_MetadataLoader::class)) {
            return;
        }

        try {
            $loader = new AFS_MetadataLoader($paths);
        } catch (\Throwable $e) {
            return;
        }

        $articleMeta = $loader->loadArticleMetadata();
        if ($articleMeta !== []) {
            foreach ($this->Artikel as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $number = isset($row['Artikelnummer']) ? trim((string)$row['Artikelnummer']) : '';
                if ($number !== '' && isset($articleMeta[$number])) {
                    $row['Meta_Title'] = $articleMeta[$number]['Meta_Title'] ?? null;
                    $row['Meta_Description'] = $articleMeta[$number]['Meta_Description'] ?? null;
                }
            }
            unset($row);
        }

        $categoryMeta = $loader->loadCategoryMetadata();
        if ($categoryMeta !== []) {
            foreach ($this->Warengruppe as &$row) {
                if (!is_array($row)) {
                    continue;
                }
                $name = isset($row['Bezeichnung']) ? trim((string)$row['Bezeichnung']) : '';
                if ($name !== '' && isset($categoryMeta[$name])) {
                    $row['Meta_Title'] = $categoryMeta[$name]['Meta_Title'] ?? null;
                    $row['Meta_Description'] = $categoryMeta[$name]['Meta_Description'] ?? null;
                }
            }
            unset($row);
        }
    }
}

// ===== Beispiel-Usage (nur zur Doku; in Produktion bitte in separater Datei nutzen) =====
// require __DIR__ . '/MSSQL.php';
// $mssql = new MSSQL($cfg);
// $source = new AFS($mssql);        // Klasse aus AFS-get-data.php
// $agg    = new AFS_Aggregate($source);
// var_dump(count($agg->Artikel), count($agg->Warengruppe), count($agg->Dokumente));
