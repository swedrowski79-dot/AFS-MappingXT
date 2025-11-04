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

        // Schritte dynamisch aus dem Manifest ableiten
        $entityNames = method_exists($engine, 'listEntityNames') ? $engine->listEntityNames() : [];
        $hasFileCatchers = method_exists($engine, 'hasFileCatcherSources') ? $engine->hasFileCatcherSources() : false;
        $totalSteps = count($entityNames) + ($hasFileCatchers ? 1 : 0);
        $done = 0;

        // Optional: FileCatcher ausführen
        if ($hasFileCatchers) {
            $tracker->advance('filecatcher', ['message' => 'Analysiere Mediendateien...', 'total' => $totalSteps, 'processed' => $done]);
            $fileCatcherSummary = $this->runFileCatchers($pdo);
            if ($fileCatcherSummary !== []) {
                $summary['filecatcher'] = $fileCatcherSummary;
                foreach ($fileCatcherSummary as $name => $stats) {
                    $tracker->logInfo(sprintf('FileCatcher %s abgeschlossen', $name), $stats, 'filecatcher');
                }
            }
            $done++; $tracker->advance('filecatcher', ['processed' => $done, 'total' => $totalSteps]);
        }

        // Entities in deklarierter Reihenfolge synchronisieren
        foreach ($entityNames as $entity) {
            $stage = (string)$entity;
            $tracker->advance($stage, ['message' => 'Synchronisiere ' . $stage . '...', 'total' => $totalSteps, 'processed' => $done]);
            try {
                $stats = $engine->syncEntity($stage, $pdo);
                $summary[$stage] = $stats;
                $tracker->logInfo(sprintf('Entity %s synchronisiert', $stage), $stats, $stage);
            } catch (Throwable $e) {
                $tracker->logWarning(sprintf('Entity %s übersprungen: %s', $stage, $e->getMessage()), [], $stage);
            }
            $done++; $tracker->advance($stage, ['processed' => $done, 'total' => $totalSteps]);
        }

        $overallDuration = microtime(true) - $overallStart;
        $totProcessed = 0;
        $totErrors = 0;
        foreach (['warengruppe', 'artikel', 'artikel_meta', 'media_bilder', 'media_dokumente', 'media_relation_bilder', 'media_relation_dokumente'] as $key) {
            $stats = $summary[$key] ?? null;
            if (is_array($stats)) {
                $totProcessed += (int)($stats['processed'] ?? 0);
                $totErrors += (int)($stats['errors'] ?? 0);
            }
        }
        $tracker->logInfo('Synchronisation abgeschlossen', [
            'gesamt_verarbeitet' => $totProcessed,
            'gesamt_fehler' => $totErrors,
            'dauer_s' => round($overallDuration, 2),
            'zusammenfassung' => $summary,
            'hinweis' => 'Detaillierte Step-Logs siehe status.sync_log',
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
