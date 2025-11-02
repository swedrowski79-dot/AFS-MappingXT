<?php
declare(strict_types=1);

class SyncService
{
    /** @var array<string,mixed> */
    private array $config;

    /** @param array<string,mixed> $config */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Führt den vollständigen Mapping-Lauf aus (mapping-only).
     * Gibt status, summary und duration zurück.
     *
     * @return array<string,mixed>
     */
    public function run(?string $manifestOverride = null): array
    {
        // createMappingOnlyEnvironment wird in api/_bootstrap.php definiert
        [$tracker, $engine, $sourceConnections, $pdo] = createMappingOnlyEnvironment($this->config, 'categories', $manifestOverride);
        $logger = createMappingLogger($this->config);

        $label = $manifestOverride ? ('Starte Synchronisation: ' . basename($manifestOverride)) : 'Starte Synchronisation';
        $tracker->begin('mapping', $label);
        $overallStart = microtime(true);

        $summary = [];

        $totalSteps = 8; // warengruppe, artikel, artikel_meta, filecatcher, 4x media*
        $done = 0;

        // 1) Warengruppen (optional)
        $tracker->advance('warengruppen', ['message' => 'Synchronisiere Warengruppen...', 'total' => $totalSteps, 'processed' => $done]);
        try {
            $wg = $engine->syncEntity('warengruppe', $pdo);
            $summary['warengruppe'] = $wg;
            $tracker->logInfo('Warengruppen synchronisiert', $wg, 'warengruppen');
        } catch (Throwable $e) {
            // Entity ggf. nicht definiert – weiter mit Artikeln
        }
        $done++; $tracker->advance('warengruppen', ['processed' => $done, 'total' => $totalSteps]);

        // 2) Artikel
        $tracker->advance('artikel', ['message' => 'Synchronisiere Artikel...', 'total' => $totalSteps, 'processed' => $done]);
        $art = $engine->syncEntity('artikel', $pdo);
        $summary['artikel'] = $art;
        $tracker->logInfo('Artikel synchronisiert', $art, 'artikel');
        $done++; $tracker->advance('artikel', ['processed' => $done, 'total' => $totalSteps]);

        // 2a) Artikel-Metadaten (FileDB)
        $tracker->advance('artikel_meta', ['message' => 'Aktualisiere Artikel-Metadaten...', 'total' => $totalSteps, 'processed' => $done]);
        try {
            $artMeta = $engine->syncEntity('artikel_meta', $pdo);
            $summary['artikel_meta'] = $artMeta;
            $tracker->logInfo('Artikel-Metadaten aktualisiert', $artMeta, 'artikel_meta');
        } catch (Throwable $e) {
            $tracker->logWarning('Artikel-Metadaten nicht synchronisiert: ' . $e->getMessage(), [], 'artikel_meta');
        }
        $done++; $tracker->advance('artikel_meta', ['processed' => $done, 'total' => $totalSteps]);

        // 3) FileCatcher (Bilder & Dokumente)
        $tracker->advance('filecatcher', ['message' => 'Analysiere Mediendateien...', 'total' => $totalSteps, 'processed' => $done]);
        $fileCatcherSummary = $this->runFileCatchers($pdo);
        if ($fileCatcherSummary !== []) {
            $summary['filecatcher'] = $fileCatcherSummary;
            foreach ($fileCatcherSummary as $name => $stats) {
                $tracker->logInfo(sprintf('FileCatcher %s abgeschlossen', $name), $stats, 'filecatcher');
            }
        }
        $done++; $tracker->advance('filecatcher', ['processed' => $done, 'total' => $totalSteps]);

        foreach (['media_bilder', 'media_dokumente', 'media_relation_bilder', 'media_relation_dokumente'] as $entity) {
            $tracker->advance($entity, ['message' => 'Synchronisiere ' . $entity . '...', 'total' => $totalSteps, 'processed' => $done]);
            try {
                $stats = $engine->syncEntity($entity, $pdo);
                $summary[$entity] = $stats;
                $tracker->logInfo(sprintf('Entity %s synchronisiert', $entity), $stats, $entity);
            } catch (Throwable $e) {
                $tracker->logWarning(sprintf('Entity %s übersprungen: %s', $entity, $e->getMessage()), [], $entity);
            }
            $done++; $tracker->advance($entity, ['processed' => $done, 'total' => $totalSteps]);
        }

        $overallDuration = microtime(true) - $overallStart;
        $totProcessed = 0;
        $totErrors = 0;
        foreach (['warengruppe', 'artikel', 'artikel_meta', 'media_bilder', 'media_dokumente', 'media_relation_bilder', 'media_relation_dokumente'] as $key) {
            $totProcessed += (int)($summary[$key]['processed'] ?? 0);
            $totErrors += (int)($summary[$key]['errors'] ?? 0);
        }
        $tracker->logInfo('Synchronisation abgeschlossen', [
            'gesamt_verarbeitet' => $totProcessed,
            'gesamt_fehler' => $totErrors,
            'dauer_s' => round($overallDuration, 2),
            'zusammenfassung' => $summary,
        ], 'abschluss');
        if ($logger) {
            $logger->log('info', 'mapping_complete', 'Synchronisation abgeschlossen', [
                'processed_total' => $totProcessed,
                'errors_total' => $totErrors,
                'duration_seconds' => $overallDuration,
            ]);
        }
        $tracker->complete([
            'message' => sprintf('Mapping abgeschlossen (%ss)', number_format($overallDuration, 2)),
        ]);

        // Datenquellen schließen (z. B. MSSQL)
        foreach ($sourceConnections as $connection) {
            if ($connection instanceof MSSQL_Connection) {
                $connection->close();
            }
        }

        return [
            'status' => $tracker->getStatus(),
            'summary' => $summary,
            'duration_seconds' => $overallDuration,
        ];
    }

    /**
     * @return array<string,array<string,int>>
     */
    private function runFileCatchers(PDO $pdo): array
    {
        $files = [
            'bilder' => afs_prefer_path('AFS_Bilder_filecatcher.yml', 'schemas'),
            'dokumente' => afs_prefer_path('AFS_Dokumente_filecatcher.yml', 'schemas'),
        ];

        $results = [];
        foreach ($files as $name => $path) {
            if (!is_string($path) || $path === '' || !is_file($path)) {
                continue;
            }
            try {
                $engine = FileCatcherEngine::fromFile($path, $this->config);
                $stats = $engine->run($pdo);
                $results[$name] = $stats;
            } catch (Throwable $e) {
                error_log(sprintf('[FileCatcher:%s] Fehler: %s', $name, $e->getMessage()));
            }
        }
        return $results;
    }
}
