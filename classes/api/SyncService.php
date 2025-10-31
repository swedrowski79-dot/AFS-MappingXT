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
        [$tracker, $engine, $sourceConnection, $pdo] = createMappingOnlyEnvironment($this->config, 'mapping');
        $logger = createMappingLogger($this->config);

        $tracker->begin('mapping', 'Starte Synchronisation');
        $overallStart = microtime(true);

        $summary = [];
        // 1) Warengruppen (optional)
        try {
            $wg = $engine->syncEntity('warengruppe', $sourceConnection, $pdo);
            $summary['warengruppe'] = $wg;
            $tracker->logInfo('Warengruppen synchronisiert', $wg, 'warengruppen');
        } catch (Throwable $e) {
            // Entity ggf. nicht definiert – weiter mit Artikeln
        }
        // 2) Artikel
        $art = $engine->syncEntity('artikel', $sourceConnection, $pdo);
        $summary['artikel'] = $art;
        $tracker->logInfo('Artikel synchronisiert', $art, 'artikel');

        // 3) FileCatcher (Bilder & Dokumente)
        $fileCatcherSummary = $this->runFileCatchers($pdo);
        if ($fileCatcherSummary !== []) {
            $summary['filecatcher'] = $fileCatcherSummary;
            foreach ($fileCatcherSummary as $name => $stats) {
                $tracker->logInfo(sprintf('FileCatcher %s abgeschlossen', $name), $stats, 'filecatcher');
            }
        }

        $overallDuration = microtime(true) - $overallStart;
        $totProcessed = (int)($summary['warengruppe']['processed'] ?? 0) + (int)($summary['artikel']['processed'] ?? 0);
        $totErrors = (int)($summary['warengruppe']['errors'] ?? 0) + (int)($summary['artikel']['errors'] ?? 0);
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

        // Mssql-Verbindung schließen
        if ($sourceConnection instanceof MSSQL_Connection) {
            $mssql->close();
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
