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
    private AFS_TargetMappingConfig $targetMapping;
    private AFS_SqlBuilder $sqlBuilder;
    private AFS_HashManager $hashManager;
    /** @var array<int,string>|null */
    private ?array $articleParameterKeys = null;

    public function __construct(
        PDO $db,
        AFS $afs,
        AFS_Evo_ImageSync $imageSync,
        AFS_Evo_DocumentSync $documentSync,
        AFS_Evo_AttributeSync $attributeSync,
        AFS_Evo_CategorySync $categorySync,
        ?AFS_Evo_StatusTracker $status = null,
        ?AFS_TargetMappingConfig $targetMapping = null
    ) {
        parent::__construct($db, $afs, $status);
        $this->imageSync = $imageSync;
        $this->documentSync = $documentSync;
        $this->attributeSync = $attributeSync;
        $this->categorySync = $categorySync;
        $this->hashManager = new AFS_HashManager();
        
        // Load target mapping configuration
        if ($targetMapping === null) {
            $mappingPath = __DIR__ . '/../mappings/target_sqlite.yml';
            $this->targetMapping = new AFS_TargetMappingConfig($mappingPath);
        } else {
            $this->targetMapping = $targetMapping;
        }
        $this->sqlBuilder = new AFS_SqlBuilder($this->targetMapping);
        
        // Log mapping version
        $this->logMappingVersion();
    }
    
    /**
     * Log the target mapping version being used
     */
    private function logMappingVersion(): void
    {
        $version = $this->targetMapping->getVersion();
        if ($version !== null) {
            $this->logInfo(
                'Target-Mapping geladen',
                ['version' => $version],
                'artikel'
            );
        }
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

        // Generate SQL statements dynamically from target mapping
        $upsertSql = $this->sqlBuilder->buildEntityUpsert('articles');
        $tableName = $this->targetMapping->getTableName('articles');
        $selectIdSql = "SELECT ID FROM {$this->quoteIdent($tableName)} WHERE Artikelnummer = :artikelnummer";
        
        $insertImageSql = $this->buildRelationshipInsertSql('article_images');
        $deleteImageSql = $this->buildRelationshipDeleteSql('article_images', ['Artikel_ID', 'Bild_ID']);
        
        $insertAttrSql = $this->buildRelationshipInsertSql('article_attributes');
        $deleteAttrSql = $this->buildRelationshipDeleteSql('article_attributes', ['Artikel_ID', 'Attribute_ID']);
        
        $insertDocSql = $this->buildRelationshipInsertSql('article_documents');
        $deleteDocSql = $this->buildRelationshipDeleteSql('article_documents', ['Artikel_ID', 'Dokument_ID']);
        
        $deactivateSql = "UPDATE {$this->quoteIdent($tableName)} SET Online = 0, " . $this->quoteIdent('update') . " = 1 WHERE ID = :id";
        $markArticleUpdateSql = "UPDATE {$this->quoteIdent($tableName)} SET " . $this->quoteIdent('update') . " = 1 WHERE ID = :id";
        $updateSeenSql = "UPDATE {$this->quoteIdent($tableName)} SET " . $this->quoteIdent('last_seen_hash') . " = :hash WHERE ID = :id";

        $this->db->beginTransaction();
        try {
            $upsert       = $this->db->prepare($upsertSql);
            $selectId     = $this->db->prepare($selectIdSql);
            $insertImage  = $this->db->prepare($insertImageSql);
            $deleteImage  = $this->db->prepare($deleteImageSql);
            $insertAttr   = $this->db->prepare($insertAttrSql);
            $deleteAttr   = $this->db->prepare($deleteAttrSql);
            $deleteDoc    = $this->db->prepare($deleteDocSql);
            $insertDoc    = $this->db->prepare($insertDocSql);
            $deactivate   = $this->db->prepare($deactivateSql);
            $markArticleUpdate = $this->db->prepare($markArticleUpdateSql);
            $updateLastSeen = $this->db->prepare($updateSeenSql);

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
                $oldEan     = $existing['ean'] ?? null;
                $newTs      = $this->toTimestamp($payload['last_update'] ?? null);

                $payload['eannummer'] = $this->sanitizeEan($payload['eannummer'], $artikelnummer, $eanMap);

                // Compute full hash of current data for efficient change detection
                $hashableFields = $this->hashManager->extractHashableFields($payload);
                $currentHash = $this->hashManager->generateHash($hashableFields);
                $existingHash = $existing['last_imported_hash'] ?? null;

                // Determine if update is needed based on hash comparison
                $shouldUpdate = $this->hashManager->hasChanged($existingHash, $currentHash);

                // Always persist last_seen_hash (if unchanged, we update via dedicated statement)
                $payload['last_seen_hash'] = $currentHash;

                if ($existing !== null) {
                    $artikelMap[$artikelnummer]['seen'] = true;
                }

                $artikelId = $existingId;

                if ($shouldUpdate) {
                    // Set update flag and persist last_imported_hash (matching last_seen_hash on update)
                    $payload['update'] = 1;
                    $payload['last_imported_hash'] = $currentHash;
                    $upsert->execute($this->prepareArticleUpsertParams($payload));

                    if ($artikelId === null) {
                        $artikelId = (int)$this->db->lastInsertId();
                    }
                    if ($artikelId === null || $artikelId <= 0) {
                        $selectId->execute([':artikelnummer' => $artikelnummer]);
                        $artikelId = (int)$selectId->fetchColumn();
                    }
                    if ($artikelId > 0) {
                        $artikelMap[$artikelnummer] = [
                            'id' => $artikelId,
                            'last_update' => $payload['last_update'] ?? null,
                            'last_update_ts' => $newTs,
                            'online' => $payload['online'],
                            'ean' => $payload['eannummer'],
                            'meta_title' => $payload['meta_title'] ?? null,
                            'meta_description' => $payload['meta_description'] ?? null,
                            'last_imported_hash' => $currentHash,
                            'last_seen_hash' => $currentHash,
                            'seen' => true,
                        ];
                        if ($payload['eannummer'] !== null) {
                            $eanMap[$payload['eannummer']] = $artikelnummer;
                        }
                    }

                    if ($existingId === null) {
                        $stats['inserted']++;
                    } else {
                        $stats['updated']++;
                        if ($oldEan !== null && $oldEan !== $payload['eannummer'] && isset($eanMap[$oldEan]) && $eanMap[$oldEan] === $artikelnummer) {
                            unset($eanMap[$oldEan]);
                        }
                        if ($payload['eannummer'] !== null) {
                            $eanMap[$payload['eannummer']] = $artikelnummer;
                        }
                        $artikelMap[$artikelnummer]['last_update'] = $payload['last_update'] ?? null;
                        $artikelMap[$artikelnummer]['last_update_ts'] = $newTs;
                        $artikelMap[$artikelnummer]['online'] = $payload['online'];
                        $artikelMap[$artikelnummer]['ean'] = $payload['eannummer'];
                        $artikelMap[$artikelnummer]['meta_title'] = $payload['meta_title'] ?? null;
                        $artikelMap[$artikelnummer]['meta_description'] = $payload['meta_description'] ?? null;
                        $artikelMap[$artikelnummer]['last_imported_hash'] = $currentHash;
                        $artikelMap[$artikelnummer]['last_seen_hash'] = $currentHash;
                    }
                } else {
                    if ($artikelId !== null && $artikelId > 0) {
                        $storedSeenHash = $existing['last_seen_hash'] ?? null;
                        if ($storedSeenHash !== $currentHash) {
                            $updateLastSeen->execute([
                                ':hash' => $currentHash,
                                ':id' => $artikelId,
                            ]);
                        }
                        $artikelMap[$artikelnummer]['last_seen_hash'] = $currentHash;
                    }
                }

                if ($artikelId === null || $artikelId <= 0) {
                    continue;
                }

                $relationsChanged = false;

                // Sync image relationships regardless of article hash changes
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

                // Sync document relationships regardless of article hash changes
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

                // Sync attribute relationships regardless of article hash changes
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
                        'ids' => array_slice($missing, 0, self::ERROR_SAMPLE_SIZE),
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
                    ':xt_artikel_id' => null,
                    ':xt_bild_id' => null,
                    ':artikel_id' => $artikelId,
                    ':bild_id' => $bildId,
                    ':update' => 1,
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
        $afsid = $payload['afs_id'] ?? null;
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
                    ':xt_artikel_id' => null,
                    ':xt_dokument_id' => null,
                    ':artikel_id' => $artikelId,
                    ':dokument_id' => $docId,
                    ':update' => 1,
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
                    ':xt_attrib_id' => null,
                    ':xt_artikel_id' => null,
                    ':attribute_id' => $attributeId,
                    ':artikel_id' => $artikelId,
                    ':atrribvalue' => $value,
                    ':update' => 1,
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
        $tableName = $this->targetMapping->getRelationshipTableName('article_images');
        $sql = "SELECT Artikel_ID, Bild_ID FROM {$this->quoteIdent($tableName)}";
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
        $tableName = $this->targetMapping->getRelationshipTableName('article_documents');
        $sql = "SELECT Artikel_ID, Dokument_ID FROM {$this->quoteIdent($tableName)}";
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
        $tableName = $this->targetMapping->getRelationshipTableName('article_attributes');
        $sql = "SELECT Artikel_ID, Attribute_ID, Atrribvalue FROM {$this->quoteIdent($tableName)}";
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

        // Build payload with lowercase parameter names for SQL binding
        return [
            'afs_id'            => isset($row['Artikel']) ? (int)$row['Artikel'] : null,
            'xt_id'             => null,
            'art'               => $this->nullIfEmpty($row['Art'] ?? null),
            'artikelnummer'     => $artikelnummer,
            'bezeichnung'       => $this->nullIfEmpty($row['Bezeichnung'] ?? null),
            'eannummer'         => $this->nullIfEmpty($row['EANNummer'] ?? null),
            'bestand'           => $this->intOrNull($row['Bestand'] ?? null),
            'preis'             => $this->floatOrNull($row['Preis'] ?? null),
            'afs_warengruppe_id'=> $warengruppe,
            'xt_category_id'    => null,
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
            // Image fields for hash calculation (note: these are not stored in Artikel table,
            // only used for computing the full hash to detect changes in image relationships)
            'bild1'             => $this->nullIfEmpty($row['Bild1'] ?? null),
            'bild2'             => $this->nullIfEmpty($row['Bild2'] ?? null),
            'bild3'             => $this->nullIfEmpty($row['Bild3'] ?? null),
            'bild4'             => $this->nullIfEmpty($row['Bild4'] ?? null),
            'bild5'             => $this->nullIfEmpty($row['Bild5'] ?? null),
            'bild6'             => $this->nullIfEmpty($row['Bild6'] ?? null),
            'bild7'             => $this->nullIfEmpty($row['Bild7'] ?? null),
            'bild8'             => $this->nullIfEmpty($row['Bild8'] ?? null),
            'bild9'             => $this->nullIfEmpty($row['Bild9'] ?? null),
            'bild10'            => $this->nullIfEmpty($row['Bild10'] ?? null),
            'update'            => 0,
            'last_update'       => $this->nullIfEmpty($row['last_update'] ?? null),
            'last_imported_hash' => null,
            'last_seen_hash'    => null,
        ];
    }

    private function getArticleParameterKeys(): array
    {
        if ($this->articleParameterKeys === null) {
            $mapping = $this->sqlBuilder->getParameterMapping('articles');
            $this->articleParameterKeys = array_map(
                static fn(string $param): string => ':' . $param,
                array_values($mapping)
            );
        }
        return $this->articleParameterKeys;
    }

    private function prepareArticleUpsertParams(array $payload): array
    {
        $params = [];
        foreach ($this->getArticleParameterKeys() as $placeholder) {
            $key = ltrim($placeholder, ':');
            $params[$placeholder] = $payload[$key] ?? null;
        }
        return $params;
    }
    
    /**
     * Build INSERT ... ON CONFLICT UPDATE SQL for relationship
     */
    private function buildRelationshipInsertSql(string $relationshipName): string
    {
        return $this->sqlBuilder->buildRelationshipUpsert($relationshipName);
    }
    
    /**
     * Build DELETE SQL for relationship
     */
    private function buildRelationshipDeleteSql(string $relationshipName, array $whereFields): string
    {
        return $this->sqlBuilder->buildRelationshipDelete($relationshipName, $whereFields);
    }

    private function loadArtikelnummerMap(): array
    {
        $map = [];
        $tableName = $this->targetMapping->getTableName('articles');
        $sql = "SELECT ID, Artikelnummer, Online, EANNummer, last_update, Meta_Title, Meta_Description, last_imported_hash, last_seen_hash FROM {$this->quoteIdent($tableName)}";
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
                'last_imported_hash' => $row['last_imported_hash'] ?? null,
                'last_seen_hash' => $row['last_seen_hash'] ?? null,
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
