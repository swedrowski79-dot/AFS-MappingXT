<?php

class EVO
{
    private PDO $db;
    private EVO_ImageSync $images;
    private EVO_DocumentSync $documents;
    private EVO_AttributeSync $attributes;
    private EVO_CategorySync $categories;
    private EVO_ArticleSync $articles;
    private ?STATUS_Tracker $status;
    private ?STATUS_MappingLogger $logger;
    private AFS $afs;
    /** @var array<string,mixed> */
    private array $config;
    private ?MSSQL_Connection $sourceConnection = null;
    private ?MappingSyncEngine $mappingEngine = null;
    private bool $mappingEngineChecked = false;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $db, AFS $afs, ?STATUS_Tracker $status = null, array $config = [], ?STATUS_MappingLogger $logger = null)
    {
        $this->db        = $db;
        $this->status     = $status;
        $this->logger     = $logger;
        $this->afs        = $afs;
        $this->config     = $config;
        $this->images     = new EVO_ImageSync($db, $afs, $status);
        $this->documents  = new EVO_DocumentSync($db, $afs, $status);
        $this->attributes = new EVO_AttributeSync($db, $afs, $status);
        $this->categories = new EVO_CategorySync($db, $afs, $status);
        $this->articles   = new EVO_ArticleSync($db, $afs, $this->images, $this->documents, $this->attributes, $this->categories, $status);
    }

    public function setSourceConnection(MSSQL_Connection $connection): void
    {
        $this->sourceConnection = $connection;
    }

    public function importBilder(): array
    {
        return $this->images->import();
    }

    public function importDokumente(): array
    {
        return $this->documents->import();
    }

    public function importAttribute(): array
    {
        return $this->attributes->import();
    }

    public function importWarengruppen(): array
    {
        if ($engine = $this->getMappingEngine()) {
            return $engine->syncEntity('warengruppe', $this->sourceConnection, $this->db);
        }
        return $this->categories->import();
    }

    public function importArtikel(): array
    {
        if ($engine = $this->getMappingEngine()) {
            return $engine->syncEntity('artikel', $this->sourceConnection, $this->db);
        }
        return $this->articles->import();
    }

    private function getMappingEngine(): ?MappingSyncEngine
    {
        if ($this->mappingEngineChecked) {
            return $this->mappingEngine;
        }
        $this->mappingEngineChecked = true;

        if ($this->sourceConnection === null) {
            return null;
        }

        $primary = $this->config['sync_mappings']['primary'] ?? null;
        if (!is_array($primary) || empty($primary['enabled'])) {
            return null;
        }

        $sourcePath = isset($primary['source']) ? (string)$primary['source'] : '';
        $schemaPath = isset($primary['schema']) ? (string)$primary['schema'] : (string)($primary['target'] ?? '');
        $rulesPath  = isset($primary['rules'])  ? (string)$primary['rules']  : '';

        if ($sourcePath === '' || $schemaPath === '' || $rulesPath === '') {
            return null;
        }

        if (!is_file($sourcePath) || !is_file($schemaPath) || !is_file($rulesPath)) {
            $this->logWarning(
                'Mapping-Konfiguration unvollständig – falle auf Legacy-Sync zurück',
                [
                    'source' => $sourcePath,
                    'schema' => $schemaPath,
                    'rules'  => $rulesPath,
                ],
                'mapping'
            );
            return null;
        }

        try {
            $this->mappingEngine = MappingSyncEngine::fromFiles($sourcePath, $schemaPath, $rulesPath);
        } catch (\Throwable $e) {
            $this->logError(
                'MappingSyncEngine konnte nicht initialisiert werden',
                ['error' => $e->getMessage()],
                'mapping'
            );
            $this->mappingEngine = null;
        }

        return $this->mappingEngine;
    }

    public function syncAll(bool $copyImages = false, ?string $imageSourceDir = null, ?string $imageDestDir = null, bool $copyDocuments = false, ?string $documentSourceDir = null, ?string $documentDestDir = null): array
    {
        $summary = [];
        $overallStart = microtime(true);
        $currentStage = 'initialisierung';
        $this->status?->begin($currentStage, 'Starte Synchronisation');

        // Log sync start
        $this->logger?->logSyncStart([
            'copy_images' => $copyImages,
            'copy_documents' => $copyDocuments,
            'image_source' => $imageSourceDir,
            'document_source' => $documentSourceDir,
        ]);

        try {
            $currentStage = 'bilder';
            [$summary['bilder'], $duration] = $this->executeStage($currentStage, 'Importiere Bilder', fn () => $this->importBilder());
            $this->status?->advance($currentStage, [
                'message' => sprintf('Bilder importiert (%d Einträge, %s)', count($summary['bilder']), $this->formatDuration($duration)),
            ]);
            $this->logger?->logStageComplete($currentStage, $duration, [
                'total_records' => count($summary['bilder']),
            ]);

            $imageConfig = $this->config['paths']['media']['images'] ?? [];
            $defaultImageSource = $imageConfig['source'] ?? null;
            $defaultImageTarget = $imageConfig['target'] ?? null;
            $shouldCopyImages = $copyImages || ($imageSourceDir === null && $defaultImageSource && is_dir($defaultImageSource));
            if ($shouldCopyImages) {
                $currentStage = 'bilder_kopieren';
                $src = $imageSourceDir ?? $defaultImageSource;
                $dest = $imageDestDir ?? $defaultImageTarget;
                if ($src) {
                    [$summary['bilder_copy'], $copyDuration] = $this->executeStage($currentStage, 'Kopiere Bilddateien', fn () => $this->images->copy($src, $dest));
                } else {
                    $summary['bilder_copy'] = ['copied' => [], 'missing' => [], 'failed' => [], 'total_unique' => 0];
                    $copyDuration = 0.0;
                    $this->status?->logWarning('Bildkopie übersprungen – kein Quellpfad konfiguriert', [], $currentStage);
                }
                $copyResult = $summary['bilder_copy'];
                $copied = count($copyResult['copied'] ?? []);
                $missing = count($copyResult['missing'] ?? []);
                $failed = count($copyResult['failed'] ?? []);
                $uniqueTotal = $copyResult['total_unique'] ?? ($copied + $missing + $failed);
                $this->status?->advance($currentStage, [
                    'message' => sprintf(
                        'Bildkopie beendet (%d/%d erfolgreich, %d fehlend, %d Fehler, %s)',
                        $copied,
                        $uniqueTotal,
                        $missing,
                        $failed,
                        $this->formatDuration($copyDuration)
                    ),
                ]);
            }

            $currentStage = 'dokumente';
            [$summary['dokumente'], $docDuration] = $this->executeStage($currentStage, 'Importiere Dokumente', fn () => $this->importDokumente());
            $this->status?->advance($currentStage, [
                'message' => sprintf('Dokumente importiert (%d Einträge, %s)', count($summary['dokumente']), $this->formatDuration($docDuration)),
            ]);

            $documentConfig = $this->config['paths']['media']['documents'] ?? [];
            $defaultDocSource = $documentConfig['source'] ?? null;
            $defaultDocTarget = $documentConfig['target'] ?? null;
            $shouldCopyDocs = $copyDocuments || ($documentSourceDir === null && $defaultDocSource && is_dir($defaultDocSource));
            if ($shouldCopyDocs) {
                $currentStage = 'dokumente_kopieren';
                $src = $documentSourceDir ?? $defaultDocSource;
                $dest = $documentDestDir ?? $defaultDocTarget;
                if ($src) {
                    [$summary['dokumente_copy'], $docCopyDuration] = $this->executeStage($currentStage, 'Kopiere Dokumente', fn () => $this->documents->copy($src, $dest));
                } else {
                    $summary['dokumente_copy'] = ['copied' => [], 'missing' => [], 'failed' => [], 'total_unique' => 0];
                    $docCopyDuration = 0.0;
                    $this->status?->logWarning('Dokumentkopie übersprungen – kein Quellpfad konfiguriert', [], $currentStage);
                }
                $docCopyResult = $summary['dokumente_copy'];
                $docCopied = count($docCopyResult['copied'] ?? []);
                $docMissing = count($docCopyResult['missing'] ?? []);
                $docFailed = count($docCopyResult['failed'] ?? []);
                $docUnique = $docCopyResult['total_unique'] ?? ($docCopied + $docMissing + $docFailed);
                $this->status?->advance($currentStage, [
                    'message' => sprintf(
                        'Dokumentkopie beendet (%d/%d erfolgreich, %d fehlend, %d Fehler, %s)',
                        $docCopied,
                        $docUnique,
                        $docMissing,
                        $docFailed,
                        $this->formatDuration($docCopyDuration)
                    ),
                ]);
                $currentStage = 'dokumente_pruefung';
                $this->documents->analyseCopyIssues(
                    $summary['dokumente_copy'],
                    is_array($this->afs->Dokumente) ? $this->afs->Dokumente : [],
                    $currentStage
                );
            }

            $currentStage = 'attribute';
            [$summary['attribute'], $attrDuration] = $this->executeStage($currentStage, 'Importiere Attribute', fn () => $this->importAttribute());
            $this->status?->advance($currentStage, [
                'message' => sprintf('Attribute importiert (%d Einträge, %s)', count($summary['attribute']), $this->formatDuration($attrDuration)),
            ]);
            $this->logger?->logStageComplete($currentStage, $attrDuration, [
                'total_records' => count($summary['attribute']),
            ]);

            $currentStage = 'warengruppen';
            [$summary['warengruppen'], $catDuration] = $this->executeStage($currentStage, 'Importiere Warengruppen', fn () => $this->importWarengruppen());
            $wg = $summary['warengruppen'];
            if (($wg['mode'] ?? '') === 'mapping') {
                $processedCategories = (int)($wg['processed'] ?? 0);
                $categoryErrors = (int)($wg['errors'] ?? 0);
                $this->status?->advance($currentStage, [
                    'message' => sprintf('Warengruppen synchronisiert (%d verarbeitet, %d Fehler, %s)', $processedCategories, $categoryErrors, $this->formatDuration($catDuration)),
                    'processed' => $processedCategories,
                    'total' => $processedCategories,
                ]);
                $this->logger?->logRecordChanges('Warengruppen', 0, $processedCategories, 0, $processedCategories);
                $this->logger?->logStageComplete($currentStage, $catDuration, [
                    'processed' => $processedCategories,
                    'errors' => $categoryErrors,
                    'mode' => 'mapping',
                ]);
            } else {
                $this->status?->advance($currentStage, [
                    'message' => sprintf('Warengruppen importiert (neu: %d · aktualisiert: %d · Eltern: %d · %s)', $wg['inserted'] ?? 0, $wg['updated'] ?? 0, $wg['parent_set'] ?? 0, $this->formatDuration($catDuration)),
                ]);
                $this->logger?->logRecordChanges('Warengruppen', $wg['inserted'] ?? 0, $wg['updated'] ?? 0, 0, ($wg['inserted'] ?? 0) + ($wg['updated'] ?? 0));
                $this->logger?->logStageComplete($currentStage, $catDuration, [
                    'inserted' => $wg['inserted'] ?? 0,
                    'updated' => $wg['updated'] ?? 0,
                    'parent_set' => $wg['parent_set'] ?? 0,
                ]);
            }

            $currentStage = 'artikel';
            $articleTotal = is_array($this->afs->Artikel) ? count($this->afs->Artikel) : 0;
            $this->status?->advance($currentStage, [
                'message' => 'Importiere Artikel',
                'total' => $articleTotal,
            ]);
            $articleStart = microtime(true);
            $summary['artikel'] = $this->importArtikel();
            $articleDuration = microtime(true) - $articleStart;
            $art = $summary['artikel'];
            $processed = $art['processed'] ?? $articleTotal;
            if (($art['mode'] ?? '') === 'mapping') {
                $articleErrors = (int)($art['errors'] ?? 0);
                $this->status?->logInfo(
                    'Artikel-Mapping abgeschlossen',
                    [
                        'duration_seconds' => $articleDuration,
                        'duration' => $this->formatDuration($articleDuration),
                        'processed' => $processed,
                        'errors' => $articleErrors,
                    ],
                    $currentStage
                );
                $this->logger?->logRecordChanges('Artikel', 0, $processed, 0, $processed);
                $this->logger?->logStageComplete($currentStage, $articleDuration, [
                    'processed' => $processed,
                    'errors' => $articleErrors,
                    'mode' => 'mapping',
                ]);
                $this->status?->advance($currentStage, [
                    'message' => sprintf('Artikel synchronisiert (%d verarbeitet, %d Fehler, %s)', $processed, $articleErrors, $this->formatDuration($articleDuration)),
                    'processed' => $processed,
                    'total' => $processed,
                ]);
            } else {
                $this->status?->logInfo(
                    'Artikelimport abgeschlossen',
                    [
                        'duration_seconds' => $articleDuration,
                        'duration' => $this->formatDuration($articleDuration),
                        'processed' => $processed,
                        'inserted' => $art['inserted'] ?? 0,
                        'updated' => $art['updated'] ?? 0,
                        'deactivated' => $art['deactivated'] ?? 0,
                    ],
                    $currentStage
                );
                $this->logger?->logRecordChanges('Artikel', $art['inserted'] ?? 0, $art['updated'] ?? 0, $art['deactivated'] ?? 0, $processed);
                $this->logger?->logStageComplete($currentStage, $articleDuration, [
                    'processed' => $processed,
                    'inserted' => $art['inserted'] ?? 0,
                    'updated' => $art['updated'] ?? 0,
                    'deactivated' => $art['deactivated'] ?? 0,
                ]);
                $this->status?->advance($currentStage, [
                    'message' => sprintf('Artikel importiert (neu: %d · aktualisiert: %d · offline: %d · %s)', $art['inserted'] ?? 0, $art['updated'] ?? 0, $art['deactivated'] ?? 0, $this->formatDuration($articleDuration)),
                    'processed' => $processed,
                    'total' => $articleTotal,
                ]);
            }

            $deltaPath = $this->config['paths']['delta_db'] ?? null;
            if (is_string($deltaPath) && $deltaPath !== '') {
                $currentStage = 'delta_export';
                $this->status?->advance($currentStage, [
                    'message' => 'Exportiere Änderungen in Delta-Datenbank',
                    'processed' => 0,
                    'total' => 0,
                ]);

                $deltaExporter = new EVO_DeltaExporter($this->db, $deltaPath, $this->status, $this->logger);
                $deltaSummary = $deltaExporter->export();
                $summary['delta'] = $deltaSummary;

                $deltaTables = count($deltaSummary);
                $deltaRows = array_sum($deltaSummary);

                $this->status?->advance($currentStage, [
                    'message' => sprintf('Delta-Export abgeschlossen (%d Tabellen · %d Datensätze)', $deltaTables, $deltaRows),
                    'processed' => $deltaRows,
                    'total' => $deltaRows,
                ]);
            }

            $overallDuration = microtime(true) - $overallStart;
            $this->status?->logInfo(
                'Synchronisation abgeschlossen',
                [
                    'duration_seconds' => $overallDuration,
                    'duration' => $this->formatDuration($overallDuration),
                ],
                'abschluss'
            );

            // Log sync completion to file
            $this->logger?->logSyncComplete($overallDuration, $summary);

            $this->status?->complete([
                'processed' => $processed,
                'total' => $articleTotal,
                'message' => sprintf('Synchronisation erfolgreich abgeschlossen (%s)', $this->formatDuration($overallDuration)),
            ]);
        } catch (\Throwable $e) {
            $this->status?->logError($e->getMessage(), [
                'stage' => $currentStage,
                'trace' => $e->getTraceAsString(),
            ], $currentStage);
            $this->status?->fail($e->getMessage(), $currentStage);
            
            // Log error to file
            $this->logger?->logError('sync_error', $e->getMessage(), $e, [
                'stage' => $currentStage,
            ]);
            
            throw $e;
        }

        return $summary;
    }

    private function executeStage(string $stage, string $startMessage, callable $callback): array
    {
        $this->status?->advance($stage, ['message' => $startMessage]);
        $start = microtime(true);
        $result = $callback();
        $duration = microtime(true) - $start;
        $label = ucfirst(str_replace('_', ' ', $stage));
        $this->status?->logInfo(
            $label . ' abgeschlossen',
            [
                'stage' => $stage,
                'duration_seconds' => $duration,
                'duration' => $this->formatDuration($duration),
            ],
            $stage
        );

        return [$result, $duration];
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds >= 3600) {
            $hours = floor($seconds / 3600);
            $minutes = floor(($seconds % 3600) / 60);
            $secs = $seconds % 60;
            return sprintf('%dh %02dm %02.0fs', $hours, $minutes, $secs);
        }
        if ($seconds >= 60) {
            $minutes = floor($seconds / 60);
            $secs = $seconds % 60;
            return sprintf('%dm %02.0fs', $minutes, $secs);
        }
        if ($seconds >= 1) {
            return sprintf('%.2fs', $seconds);
        }
        $ms = $seconds * 1000;
        if ($ms >= 1) {
            return sprintf('%.1fms', $ms);
        }
        return sprintf('%.2fms', max($ms, 0.01));
    }
}
