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
    public function run(): array
    {
        // createMappingOnlyEnvironment wird in api/_bootstrap.php definiert
        [$tracker, $engine, $sourceConnections, $pdo] = createMappingOnlyEnvironment($this->config, 'categories');
        $logger = createMappingLogger($this->config);

        $tracker->begin('mapping', 'Starte Synchronisation');
        $overallStart = microtime(true);

        $summary = [];
        // 1) Warengruppen (optional)
        try {
            $wg = $engine->syncEntity('warengruppe', $pdo);
            $summary['warengruppe'] = $wg;
            $tracker->logInfo('Warengruppen synchronisiert', $wg, 'warengruppen');
        } catch (Throwable $e) {
            // Entity ggf. nicht definiert – weiter mit Artikeln
        }
        // 2) Artikel
        $art = $engine->syncEntity('artikel', $pdo);
        $summary['artikel'] = $art;
        $tracker->logInfo('Artikel synchronisiert', $art, 'artikel');

        // 2a) Artikel-Metadaten (FileDB)
        try {
            $artMeta = $engine->syncEntity('artikel_meta', $pdo);
            $summary['artikel_meta'] = $artMeta;
            $tracker->logInfo('Artikel-Metadaten aktualisiert', $artMeta, 'artikel_meta');
        } catch (Throwable $e) {
            $tracker->logWarning('Artikel-Metadaten nicht synchronisiert: ' . $e->getMessage(), [], 'artikel_meta');
        }

        // 3) FileCatcher (Bilder & Dokumente)
        $fileCatcherSummary = $this->runFileCatchers($pdo);
        if ($fileCatcherSummary !== []) {
            $summary['filecatcher'] = $fileCatcherSummary;
            foreach ($fileCatcherSummary as $name => $stats) {
                $tracker->logInfo(sprintf('FileCatcher %s abgeschlossen', $name), $stats, 'filecatcher');
            }

            try {
                $bilder = $engine->syncEntity('bilder', $pdo);
                $summary['bilder'] = $bilder;
                $tracker->logInfo('Bilder synchronisiert', $bilder, 'bilder');
            } catch (Throwable $e) {
                $tracker->logWarning('Bilder-Sync übersprungen: ' . $e->getMessage(), [], 'bilder');
            }

            try {
                $dokumente = $engine->syncEntity('dokumente', $pdo);
                $summary['dokumente'] = $dokumente;
                $tracker->logInfo('Dokumente synchronisiert', $dokumente, 'dokumente');
            } catch (Throwable $e) {
                $tracker->logWarning('Dokumente-Sync übersprungen: ' . $e->getMessage(), [], 'dokumente');
            }

            try {
                $mssqlConnection = $this->extractMssqlConnection($sourceConnections);
                if ($mssqlConnection instanceof MSSQL_Connection) {
                    $mediaLinkService = new MediaLinkService($pdo, $mssqlConnection);
                    $mediaSummary = $mediaLinkService->sync();
                    if ($mediaSummary !== []) {
                        $summary['media_links'] = $mediaSummary;
                        $tracker->logInfo('Medien-Verknüpfungen aktualisiert', $mediaSummary, 'media_links');
                    }
                }
            } catch (Throwable $e) {
                $tracker->logWarning('Medien-Verknüpfungen übersprungen: ' . $e->getMessage(), [], 'media_links');
            }
        }

        $overallDuration = microtime(true) - $overallStart;
        $totProcessed = 0;
        $totErrors = 0;
        foreach (['warengruppe', 'artikel', 'artikel_meta', 'bilder', 'dokumente'] as $key) {
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
     * @param array<int,mixed> $connections
     */
    private function extractMssqlConnection(array $connections): ?MSSQL_Connection
    {
        foreach ($connections as $connection) {
            if ($connection instanceof MSSQL_Connection) {
                return $connection;
            }
        }
        return null;
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
