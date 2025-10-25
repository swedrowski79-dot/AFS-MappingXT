<?php

class AFS_Evo
{
    private PDO $db;
    private AFS_Evo_ImageSync $images;
    private AFS_Evo_DocumentSync $documents;
    private AFS_Evo_AttributeSync $attributes;
    private AFS_Evo_CategorySync $categories;
    private AFS_Evo_ArticleSync $articles;
    private ?AFS_Evo_StatusTracker $status;
    private ?AFS_MappingLogger $logger;
    private AFS $afs;
    /** @var array<string,mixed> */
    private array $config;

    /**
     * @param array<string,mixed> $config
     */
    public function __construct(PDO $db, AFS $afs, ?AFS_Evo_StatusTracker $status = null, array $config = [], ?AFS_MappingLogger $logger = null)
    {
        $this->db        = $db;
        $this->status     = $status;
        $this->logger     = $logger;
        $this->afs        = $afs;
        $this->config     = $config;
        $this->images     = new AFS_Evo_ImageSync($db, $afs, $status);
        $this->documents  = new AFS_Evo_DocumentSync($db, $afs, $status);
        $this->attributes = new AFS_Evo_AttributeSync($db, $afs, $status);
        $this->categories = new AFS_Evo_CategorySync($db, $afs, $status);
        $this->articles   = new AFS_Evo_ArticleSync($db, $afs, $this->images, $this->documents, $this->attributes, $this->categories, $status);
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
        return $this->categories->import();
    }

    public function importArtikel(): array
    {
        return $this->articles->import();
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
            $this->status?->advance($currentStage, [
                'message' => sprintf('Warengruppen importiert (neu: %d · aktualisiert: %d · Eltern: %d · %s)', $wg['inserted'] ?? 0, $wg['updated'] ?? 0, $wg['parent_set'] ?? 0, $this->formatDuration($catDuration)),
            ]);
            $this->logger?->logRecordChanges('Warengruppen', $wg['inserted'] ?? 0, $wg['updated'] ?? 0, 0, ($wg['inserted'] ?? 0) + ($wg['updated'] ?? 0));
            $this->logger?->logStageComplete($currentStage, $catDuration, [
                'inserted' => $wg['inserted'] ?? 0,
                'updated' => $wg['updated'] ?? 0,
                'parent_set' => $wg['parent_set'] ?? 0,
            ]);

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

            $deltaPath = $this->config['paths']['delta_db'] ?? null;
            if (is_string($deltaPath) && $deltaPath !== '') {
                $currentStage = 'delta_export';
                $this->status?->advance($currentStage, [
                    'message' => 'Exportiere Änderungen in Delta-Datenbank',
                    'processed' => 0,
                    'total' => 0,
                ]);

                $deltaExporter = new AFS_Evo_DeltaExporter($this->db, $deltaPath, $this->status, $this->logger);
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
