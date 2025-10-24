<?php

use PDOStatement;

class AFS_Evo_ArticleSync extends AFS_Evo_Base
{
    // Constants for better code readability
    private const MAX_IMAGES_PER_ARTICLE = 10;
    private const MAX_ATTRIBUTES_PER_ARTICLE = 4;
    
    private AFS_Evo_ImageSync $imageSync;
    private AFS_Evo_DocumentSync $documentSync;
    private AFS_Evo_AttributeSync $attributeSync;
    private AFS_Evo_CategorySync $categorySync;

    public function __construct(
        PDO $db,
        AFS $afs,
        AFS_Evo_ImageSync $imageSync,
        AFS_Evo_DocumentSync $documentSync,
        AFS_Evo_AttributeSync $attributeSync,
        AFS_Evo_CategorySync $categorySync,
        ?AFS_Evo_StatusTracker $status = null
    ) {
        parent::__construct($db, $afs, $status);
        $this->imageSync = $imageSync;
        $this->documentSync = $documentSync;
        $this->attributeSync = $attributeSync;
        $this->categorySync = $categorySync;
    }

    /**
     * @return array{processed:int,inserted:int,updated:int,images:int,documents:int,attributes:int,deactivated:int}
     */
    public function import(): array
    {
        $rows = is_array($this->afs->Artikel) ? $this->afs->Artikel : [];
        if ($rows === []) {
            return ['processed'=>0,'inserted'=>0,'updated'=>0,'images'=>0,'documents'=>0,'attributes'=>0,'deactivated'=>0];
        }

        $categoryMap  = $this->categorySync->loadCategoryIdMap();
        $bildMap      = $this->imageSync->loadBildIdMap();
        $dokumentMap  = $this->documentSync->loadDocumentIdMap();
        $attributeMap = $this->attributeSync->attributeIdMapFromAfs();
        $artikelMap   = $this->loadArtikelnummerMap();
        $eanMap       = $this->buildEanMap($artikelMap);
        $docsByArticle = $this->groupDocumentsByArticle(is_array($this->afs->Dokumente) ? $this->afs->Dokumente : []);
        
        // Batch load all existing relations to avoid N+1 queries
        $existingImages = $this->loadAllArticleImageRelations();
        $existingDocs = $this->loadAllArticleDocumentRelations();
        $existingAttrs = $this->loadAllArticleAttributeRelations();

        $stats = ['processed'=>0,'inserted'=>0,'updated'=>0,'images'=>0,'documents'=>0,'attributes'=>0,'deactivated'=>0];

        $upsertSql = '
            INSERT INTO Artikel (
                AFS_ID, XT_ID, Art, Artikelnummer, Bezeichnung, EANNummer, Bestand, Preis,
                AFS_Warengruppe_ID, XT_Category_ID, Category, Master, Masterartikel, Mindestmenge,
                Gewicht, Online, Einheit, Langtext, Werbetext, Meta_Title, Meta_Description, Bemerkung, Hinweis, "update", last_update
            ) VALUES (
                :afsid, NULL, :art, :artikelnummer, :bezeichnung, :ean, :bestand, :preis,
                :afs_warengruppe_id, NULL, :category, :master, :masterartikel, :mindestmenge,
                :gewicht, :online, :einheit, :langtext, :werbetext, :meta_title, :meta_description, :bemerkung, :hinweis, :needs_update, :last_update
            )
            ON CONFLICT(Artikelnummer) DO UPDATE SET
                AFS_ID = excluded.AFS_ID,
                Art = excluded.Art,
                Bezeichnung = excluded.Bezeichnung,
                EANNummer = excluded.EANNummer,
                Bestand = excluded.Bestand,
                Preis = excluded.Preis,
                AFS_Warengruppe_ID = excluded.AFS_Warengruppe_ID,
                Category = excluded.Category,
                Master = excluded.Master,
                Masterartikel = excluded.Masterartikel,
                Mindestmenge = excluded.Mindestmenge,
                Gewicht = excluded.Gewicht,
                Online = excluded.Online,
                Einheit = excluded.Einheit,
                Langtext = excluded.Langtext,
                Werbetext = excluded.Werbetext,
                Meta_Title = excluded.Meta_Title,
                Meta_Description = excluded.Meta_Description,
                Bemerkung = excluded.Bemerkung,
                Hinweis = excluded.Hinweis,
                "update" = excluded."update",
                last_update = excluded.last_update
        ';

        $this->db->beginTransaction();
        try {
            $upsert       = $this->db->prepare($upsertSql);
            $selectId     = $this->db->prepare('SELECT ID FROM Artikel WHERE Artikelnummer = :artikelnummer');
            $insertImage  = $this->db->prepare(
                'INSERT INTO Artikel_Bilder (Artikel_ID, Bild_ID, "update")
                 VALUES (:artikel_id, :bild_id, 1)
                 ON CONFLICT(Artikel_ID, Bild_ID) DO UPDATE SET "update" = 1'
            );
            $deleteImage  = $this->db->prepare('DELETE FROM Artikel_Bilder WHERE Artikel_ID = :artikel_id AND Bild_ID = :bild_id');
            $insertAttr   = $this->db->prepare(
                'INSERT INTO Attrib_Artikel (Attribute_ID, Artikel_ID, Atrribvalue, "update")
                 VALUES (:attribute_id, :artikel_id, :value, 1)
                 ON CONFLICT(Attribute_ID, Artikel_ID) DO UPDATE SET Atrribvalue = excluded.Atrribvalue, "update" = 1'
            );
            $deleteAttr   = $this->db->prepare('DELETE FROM Attrib_Artikel WHERE Artikel_ID = :artikel_id AND Attribute_ID = :attribute_id');
            $deleteDoc    = $this->db->prepare('DELETE FROM Artikel_Dokumente WHERE Artikel_ID = :artikel_id AND Dokument_ID = :dokument_id');
            $insertDoc    = $this->db->prepare(
                'INSERT INTO Artikel_Dokumente (Artikel_ID, Dokument_ID, "update")
                 VALUES (:artikel_id, :dokument_id, 1)
                 ON CONFLICT(Artikel_ID, Dokument_ID) DO UPDATE SET "update" = 1'
            );
            $deactivate   = $this->db->prepare('UPDATE Artikel SET Online = 0, "update" = 1 WHERE ID = :id');
            $markArticleUpdate = $this->db->prepare('UPDATE Artikel SET "update" = 1 WHERE ID = :id');

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

                $payload = $this->buildArtikelPayload($row, $categoryMap);
                if ($payload === null) {
                    continue;
                }

                $stats['processed']++;
                $artikelnummer = $payload['artikelnummer'];
                $existing = $artikelMap[$artikelnummer] ?? null;
                $existingId = $existing['id'] ?? null;
                $existingTs = $existing['last_update_ts'] ?? null;
                $oldEan     = $existing['ean'] ?? null;
                $newTs      = $this->toTimestamp($payload['last_update'] ?? null);

                $payload['ean'] = $this->sanitizeEan($payload['ean'], $artikelnummer, $eanMap);

                $shouldUpdate = false;
                if ($existing === null) {
                    $shouldUpdate = true;
                } elseif ($newTs === null) {
                    $shouldUpdate = true;
                } elseif ($existingTs === null || $newTs > $existingTs) {
                    $shouldUpdate = true;
                }

                if ($existing !== null) {
                    $artikelMap[$artikelnummer]['seen'] = true;
                    if (!$shouldUpdate) {
                        $existingMetaTitle = $existing['meta_title'] ?? null;
                        $existingMetaDescription = $existing['meta_description'] ?? null;
                        if ($existingMetaTitle !== ($payload['meta_title'] ?? null) || $existingMetaDescription !== ($payload['meta_description'] ?? null)) {
                            $shouldUpdate = true;
                        }
                    }
                }

                if (!$shouldUpdate) {
                    continue;
                }

                $payload['needs_update'] = 1;
                $upsert->execute($payload);

                $artikelId = $existingId;
                if ($existingId === null) {
                    $artikelId = (int)$this->db->lastInsertId();
                    if ($artikelId <= 0) {
                        $selectId->execute([':artikelnummer' => $artikelnummer]);
                        $artikelId = (int)$selectId->fetchColumn();
                    }
                    if ($artikelId > 0) {
                        $artikelMap[$artikelnummer] = [
                            'id' => $artikelId,
                            'last_update' => $payload['last_update'] ?? null,
                            'last_update_ts' => $newTs,
                            'online' => $payload['online'],
                            'ean' => $payload['ean'],
                            'meta_title' => $payload['meta_title'] ?? null,
                            'meta_description' => $payload['meta_description'] ?? null,
                            'seen' => true,
                        ];
                        if ($payload['ean'] !== null) {
                            $eanMap[$payload['ean']] = $artikelnummer;
                        }
                    }
                    $stats['inserted']++;
                } else {
                    $stats['updated']++;
                    $artikelMap[$artikelnummer]['last_update'] = $payload['last_update'] ?? null;
                    $artikelMap[$artikelnummer]['last_update_ts'] = $newTs;
                    $artikelMap[$artikelnummer]['online'] = $payload['online'];
                    if ($oldEan !== null && $oldEan !== $payload['ean'] && isset($eanMap[$oldEan]) && $eanMap[$oldEan] === $artikelnummer) {
                        unset($eanMap[$oldEan]);
                    }
                    if ($payload['ean'] !== null) {
                        $eanMap[$payload['ean']] = $artikelnummer;
                    }
                    $artikelMap[$artikelnummer]['ean'] = $payload['ean'];
                    $artikelMap[$artikelnummer]['meta_title'] = $payload['meta_title'] ?? null;
                    $artikelMap[$artikelnummer]['meta_description'] = $payload['meta_description'] ?? null;
                    $artikelMap[$artikelnummer]['seen'] = true;
                }

                if ($artikelId <= 0) {
                    continue;
                }

                $relationsChanged = false;

                $imageResult = $this->syncArticleImages(
                    $insertImage,
                    $deleteImage,
                    $artikelId,
                    $bildMap,
                    $row,
                    $existingImages[$artikelId] ?? []
                );
                $stats['images'] += $imageResult['added'] + $imageResult['removed'];
                $relationsChanged = $relationsChanged || $imageResult['changed'];

                $docResult = $this->syncArticleDocuments(
                    $insertDoc,
                    $deleteDoc,
                    $artikelId,
                    $dokumentMap,
                    $docsByArticle,
                    $payload,
                    $existingDocs[$artikelId] ?? []
                );
                $stats['documents'] += $docResult['added'] + $docResult['removed'];
                $relationsChanged = $relationsChanged || $docResult['changed'];

                $attrResult = $this->syncArticleAttributes(
                    $insertAttr,
                    $deleteAttr,
                    $artikelId,
                    $attributeMap,
                    $row,
                    $existingAttrs[$artikelId] ?? []
                );
                $stats['attributes'] += $attrResult['added'] + $attrResult['removed'];
                $relationsChanged = $relationsChanged || $attrResult['changed'];

                if ($relationsChanged) {
                    $markArticleUpdate->execute([':id' => $artikelId]);
                }
            }

            $missing = $this->collectMissingArticles($artikelMap);
            if ($missing !== []) {
                foreach ($missing as $id) {
                    $deactivate->execute([':id' => $id]);
                }
                $stats['deactivated'] = count($missing);
                $this->logInfo(
                    'Artikel nicht mehr im Datenbestand gefunden - offline gesetzt',
                    [
                        'count' => count($missing),
                        'ids' => array_slice($missing, 0, 15),
                    ],
                    'artikel'
                );
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $stats;
    }

    /**
     * @return array{added:int,removed:int,changed:bool}
     */
    private function syncArticleImages(
        PDOStatement $insertImage,
        PDOStatement $deleteImage,
        int $artikelId,
        array $bildMap,
        array $row,
        array $existingImageIds
    ): array {
        $existingMap = array_fill_keys($existingImageIds, true);

        $desired = [];
        foreach ($this->collectArticleImages($row) as $image) {
            $bildId = $this->imageSync->resolveBildId($bildMap, $image);
            if ($bildId !== null) {
                $desired[(int)$bildId] = true;
            }
        }

        $added = 0;
        foreach (array_keys($desired) as $bildId) {
            if (!isset($existingMap[$bildId])) {
                $insertImage->execute([
                    ':artikel_id' => $artikelId,
                    ':bild_id' => $bildId,
                ]);
                $added++;
            } else {
                unset($existingMap[$bildId]);
            }
        }

        $removed = 0;
        foreach (array_keys($existingMap) as $bildId) {
            $deleteImage->execute([
                ':artikel_id' => $artikelId,
                ':bild_id' => $bildId,
            ]);
            $removed++;
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => ($added + $removed) > 0,
        ];
    }

    /**
     * @return array{added:int,removed:int,changed:bool}
     */
    private function syncArticleDocuments(
        PDOStatement $insertDoc,
        PDOStatement $deleteDoc,
        int $artikelId,
        array $dokumentMap,
        array $docsByArticle,
        array $payload,
        array $existingDocIds
    ): array {
        $existingMap = array_fill_keys($existingDocIds, true);

        $desired = [];
        $afsid = $payload['afsid'] ?? null;
        if ($afsid !== null && isset($docsByArticle[$afsid])) {
            foreach ($docsByArticle[$afsid] as $title) {
                $docId = $this->documentSync->resolveDocumentId($dokumentMap, $title);
                if ($docId !== null) {
                    $desired[(int)$docId] = true;
                }
            }
        }

        $added = 0;
        foreach (array_keys($desired) as $docId) {
            if (!isset($existingMap[$docId])) {
                $insertDoc->execute([
                    ':artikel_id' => $artikelId,
                    ':dokument_id' => $docId,
                ]);
                $added++;
            } else {
                unset($existingMap[$docId]);
            }
        }

        $removed = 0;
        foreach (array_keys($existingMap) as $docId) {
            $deleteDoc->execute([
                ':artikel_id' => $artikelId,
                ':dokument_id' => $docId,
            ]);
            $removed++;
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => ($added + $removed) > 0,
        ];
    }

    /**
     * @return array{added:int,removed:int,changed:bool}
     */
    private function syncArticleAttributes(
        PDOStatement $insertAttr,
        PDOStatement $deleteAttr,
        int $artikelId,
        array $attributeMap,
        array $row,
        array $existingAttrs
    ): array {
        $existing = $existingAttrs;

        $desired = [];
        foreach ($this->collectArticleAttributes($row, $attributeMap) as [$attributeId, $value]) {
            $desired[(int)$attributeId] = (string)$value;
        }

        $added = 0;
        foreach ($desired as $attributeId => $value) {
            if (!array_key_exists($attributeId, $existing) || $existing[$attributeId] !== $value) {
                $insertAttr->execute([
                    ':attribute_id' => $attributeId,
                    ':artikel_id' => $artikelId,
                    ':value' => $value,
                ]);
                $added++;
            }
        }

        $removed = 0;
        foreach ($existing as $attributeId => $_value) {
            if (!array_key_exists($attributeId, $desired)) {
                $deleteAttr->execute([
                    ':artikel_id' => $artikelId,
                    ':attribute_id' => $attributeId,
                ]);
                $removed++;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'changed' => ($added + $removed) > 0,
        ];
    }

    /**
     * @param array<string,array<string,mixed>> $artikelMap
     * @return array<int>
     */
    private function collectMissingArticles(array $artikelMap): array
    {
        $missing = [];
        foreach ($artikelMap as $info) {
            if (empty($info['seen']) && isset($info['id'])) {
                $missing[] = (int)$info['id'];
            }
        }
        return $missing;
    }

    /**
     * Batch load all article-image relations to avoid N+1 queries
     * @return array<int,array<int>> Map of article_id => [image_id1, image_id2, ...]
     */
    private function loadAllArticleImageRelations(): array
    {
        $map = [];
        $sql = 'SELECT Artikel_ID, Bild_ID FROM Artikel_Bilder';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $artikelId = (int)$row['Artikel_ID'];
            $bildId = (int)$row['Bild_ID'];
            if (!isset($map[$artikelId])) {
                $map[$artikelId] = [];
            }
            $map[$artikelId][] = $bildId;
        }
        return $map;
    }

    /**
     * Batch load all article-document relations to avoid N+1 queries
     * @return array<int,array<int>> Map of article_id => [doc_id1, doc_id2, ...]
     */
    private function loadAllArticleDocumentRelations(): array
    {
        $map = [];
        $sql = 'SELECT Artikel_ID, Dokument_ID FROM Artikel_Dokumente';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $artikelId = (int)$row['Artikel_ID'];
            $docId = (int)$row['Dokument_ID'];
            if (!isset($map[$artikelId])) {
                $map[$artikelId] = [];
            }
            $map[$artikelId][] = $docId;
        }
        return $map;
    }

    /**
     * Batch load all article-attribute relations to avoid N+1 queries
     * @return array<int,array<int,string>> Map of article_id => [attribute_id => value]
     */
    private function loadAllArticleAttributeRelations(): array
    {
        $map = [];
        $sql = 'SELECT Artikel_ID, Attribute_ID, Atrribvalue FROM Attrib_Artikel';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $artikelId = (int)$row['Artikel_ID'];
            $attrId = (int)$row['Attribute_ID'];
            $value = (string)$row['Atrribvalue'];
            if (!isset($map[$artikelId])) {
                $map[$artikelId] = [];
            }
            $map[$artikelId][$attrId] = $value;
        }
        return $map;
    }

    private function buildArtikelPayload(array $row, array $categoryMap): ?array
    {
        $artikelnummer = isset($row['Artikelnummer']) ? trim((string)$row['Artikelnummer']) : '';
        if ($artikelnummer === '') {
            return null;
        }

        $warengruppe = isset($row['Warengruppe']) && $row['Warengruppe'] !== '' ? (int)$row['Warengruppe'] : null;
        $categoryId = ($warengruppe !== null && isset($categoryMap[$warengruppe])) ? $categoryMap[$warengruppe] : null;

        [$masterFlag, $masterArtikel] = $this->parseMasterField($row['Master'] ?? null);

        return [
            'afsid'             => isset($row['Artikel']) ? (int)$row['Artikel'] : null,
            'art'               => $this->nullIfEmpty($row['Art'] ?? null),
            'artikelnummer'     => $artikelnummer,
            'bezeichnung'       => $this->nullIfEmpty($row['Bezeichnung'] ?? null),
            'ean'               => $this->nullIfEmpty($row['EANNummer'] ?? null),
            'bestand'           => $this->intOrNull($row['Bestand'] ?? null),
            'preis'             => $this->floatOrNull($row['Preis'] ?? null),
            'afs_warengruppe_id'=> $warengruppe,
            'category'          => $categoryId,
            'master'            => $masterFlag,
            'masterartikel'     => $masterArtikel,
            'mindestmenge'      => $this->intOrNull($row['Mindestmenge'] ?? null),
            'gewicht'           => $this->floatOrNull($row['Bruttogewicht'] ?? null),
            'online'            => $this->boolToInt($row['Online'] ?? null),
            'einheit'           => $this->nullIfEmpty($row['Einheit'] ?? null),
            'langtext'          => $this->nullIfEmpty($row['Langtext'] ?? null),
            'werbetext'         => $this->nullIfEmpty($row['Werbetext1'] ?? null),
            'meta_title'        => $this->nullIfEmpty($row['Meta_Title'] ?? null),
            'meta_description'  => $this->nullIfEmpty($row['Meta_Description'] ?? null),
            'bemerkung'         => $this->nullIfEmpty($row['Bemerkung'] ?? null),
            'hinweis'           => $this->nullIfEmpty($row['Hinweis'] ?? null),
            'needs_update'      => 0,
            'last_update'       => $this->nullIfEmpty($row['last_update'] ?? null),
        ];
    }

    private function loadArtikelnummerMap(): array
    {
        $map = [];
        $sql = 'SELECT ID, Artikelnummer, Online, EANNummer, last_update, Meta_Title, Meta_Description FROM Artikel';
        foreach ($this->db->query($sql, PDO::FETCH_ASSOC) as $row) {
            $nummer = isset($row['Artikelnummer']) ? trim((string)$row['Artikelnummer']) : '';
            if ($nummer === '') {
                continue;
            }
            $ts = $this->toTimestamp($row['last_update'] ?? null);
            $map[$nummer] = [
                'id' => (int)$row['ID'],
                'last_update' => $row['last_update'] ?? null,
                'last_update_ts' => $ts,
                'online' => isset($row['Online']) ? (int)$row['Online'] : 0,
                'ean' => $row['EANNummer'] ?? null,
                'meta_title' => $row['Meta_Title'] ?? null,
                'meta_description' => $row['Meta_Description'] ?? null,
                'seen' => false,
            ];
        }
        return $map;
    }

    private function collectArticleImages(array $row): array
    {
        $unique = [];
        for ($i = 1; $i <= self::MAX_IMAGES_PER_ARTICLE; $i++) {
            $key = 'Bild' . $i;
            if (!isset($row[$key])) {
                continue;
            }
            $name = trim((string)$row[$key]);
            if ($name === '') {
                continue;
            }
            $base = basename($name);
            if ($base === '') {
                continue;
            }
            $lower = strtolower($base);
            if (!isset($unique[$lower])) {
                $unique[$lower] = $base;
            }
        }
        return array_values($unique);
    }

    private function collectArticleAttributes(array $row, array $attributeMap): array
    {
        $out = [];
        for ($i = 1; $i <= self::MAX_ATTRIBUTES_PER_ARTICLE; $i++) {
            $nameKey  = 'Attribname' . $i;
            $valueKey = 'Attribvalue' . $i;
            if (!isset($row[$nameKey])) {
                continue;
            }
            $name = trim((string)$row[$nameKey]);
            if ($name === '' || !isset($attributeMap[$name])) {
                continue;
            }
            $value = isset($row[$valueKey]) ? trim((string)$row[$valueKey]) : '';
            if ($value === '') {
                continue;
            }
            $out[] = [$attributeMap[$name], $value];
        }
        return $out;
    }

    private function groupDocumentsByArticle(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $afsid = isset($row['Artikel']) && $row['Artikel'] !== '' ? (int)$row['Artikel'] : null;
            if ($afsid === null || $afsid <= 0) {
                continue;
            }
            $title = isset($row['Titel']) ? trim((string)$row['Titel']) : '';
            if ($title === '') {
                continue;
            }
            if (!isset($out[$afsid])) {
                $out[$afsid] = [];
            }
            $key = strtolower($title);
            if (!isset($out[$afsid][$key])) {
                $out[$afsid][$key] = $title;
            }
        }

        foreach ($out as $afsid => $titles) {
            $out[$afsid] = array_values($titles);
        }

        return $out;
    }

    private function parseMasterField($value): array
    {
        $str = trim((string)$value);
        if ($str === '') {
            return [0, null];
        }
        if (strcasecmp($str, 'master') === 0) {
            return [1, null];
        }
        return [0, $str];
    }

    /**
     * Build a reverse map from EAN to article number for duplicate detection
     * @param array<string,array<string,mixed>> $artikelMap
     * @return array<string,string>
     */
    private function buildEanMap(array $artikelMap): array
    {
        $map = [];
        foreach ($artikelMap as $nummer => $info) {
            $ean = isset($info['ean']) ? trim((string)$info['ean']) : '';
            if ($ean === '') {
                continue;
            }
            $map[$ean] = $nummer;
        }
        return $map;
    }

    /**
     * Validates and sanitizes EAN, prevents duplicate EANs across articles
     * @param array<string,string> $map Reference to EAN->ArticleNumber map
     */
    private function sanitizeEan(?string $ean, string $artikelnummer, array &$map): ?string
    {
        if ($ean === null) {
            return null;
        }
        $eanTrim = trim($ean);
        if ($eanTrim === '') {
            return null;
        }
        // Check if EAN is already assigned to a different article
        if (isset($map[$eanTrim]) && $map[$eanTrim] !== $artikelnummer) {
            $this->logWarning(
                'EAN wird verworfen - bereits einem anderen Artikel zugeordnet',
                [
                    'ean' => $eanTrim,
                    'artikel' => $artikelnummer,
                    'konflikt_mit' => $map[$eanTrim],
                ],
                'artikel'
            );
            return null;
        }
        return $eanTrim;
    }
}
