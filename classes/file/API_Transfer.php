<?php
declare(strict_types=1);

/**
 * API_Transfer - Server-to-Server Data Transfer
 * 
 * Handles secure transfer of delta databases, images, and documents
 * between different servers using API key authentication.
 * 
 * Features:
 * - API key authentication
 * - Delta database transfer
 * - Images directory transfer
 * - Documents directory transfer
 * - Transfer logging and error handling
 * - File size validation
 */
class API_Transfer
{
    private array $config;
    private ?STATUS_MappingLogger $logger;
    private array $transferResults = [];

    public function __construct(array $config, ?STATUS_MappingLogger $logger = null)
    {
        $this->config = $config['data_transfer'] ?? [];
        $this->logger = $logger;
        
        // Validate configuration
        if (empty($this->config['api_key'])) {
            throw new AFS_ConfigurationException('DATA_TRANSFER_API_KEY ist nicht konfiguriert');
        }
    }

    /**
     * Validate API key
     */
    public function validateApiKey(string $providedKey): bool
    {
        $configuredKey = $this->config['api_key'] ?? '';
        
        if (empty($configuredKey)) {
            return false;
        }
        
        // Use hash_equals to prevent timing attacks
        return hash_equals($configuredKey, $providedKey);
    }

    /**
     * Transfer delta database from source to target
     */
    public function transferDatabase(): array
    {
        $dbConfig = $this->config['database'] ?? [];
        
        if (!($dbConfig['enabled'] ?? false)) {
            return [
                'success' => false,
                'message' => 'Datenbank-Transfer ist deaktiviert',
                'skipped' => true,
            ];
        }
        
        $source = $dbConfig['source'] ?? '';
        $target = $dbConfig['target'] ?? '';
        
        if (empty($source) || empty($target)) {
            throw new AFS_ConfigurationException('Datenbank-Quell- oder Zielpfad nicht konfiguriert');
        }
        
        if (!file_exists($source)) {
            throw new AFS_FileException("Quell-Datenbank nicht gefunden: {$source}");
        }
        
        // Ensure target directory exists
        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new AFS_FileException("Zielverzeichnis konnte nicht erstellt werden: {$targetDir}");
            }
        }
        
        // Get file size
        $fileSize = filesize($source);
        $maxSize = $this->config['max_file_size'] ?? 104857600;
        
        if ($fileSize > $maxSize) {
            throw new AFS_FileException("Datenbank ist zu groß: {$fileSize} Bytes (Max: {$maxSize} Bytes)");
        }
        
        // Copy database file
        $startTime = microtime(true);
        $success = copy($source, $target);
        $duration = microtime(true) - $startTime;
        
        if (!$success) {
            throw new AFS_FileException("Datenbank-Transfer fehlgeschlagen: {$source} -> {$target}");
        }
        
        $result = [
            'success' => true,
            'source' => $source,
            'target' => $target,
            'size' => $fileSize,
            'duration' => round($duration, 3),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        $this->logTransfer('database', $result);
        $this->transferResults['database'] = $result;
        
        return $result;
    }

    /**
     * Transfer images from source to target directory
     */
    public function transferImages(): array
    {
        return $this->transferDirectory(
            'images',
            $this->config['images'] ?? []
        );
    }

    /**
     * Transfer documents from source to target directory
     */
    public function transferDocuments(): array
    {
        return $this->transferDirectory(
            'documents',
            $this->config['documents'] ?? []
        );
    }

    /**
     * Transfer all configured data types
     */
    public function transferAll(): array
    {
        $results = [];
        
        // Transfer database
        try {
            $results['database'] = $this->transferDatabase();
        } catch (Throwable $e) {
            $results['database'] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
        
        // Transfer images
        try {
            $results['images'] = $this->transferImages();
        } catch (Throwable $e) {
            $results['images'] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
        
        // Transfer documents
        try {
            $results['documents'] = $this->transferDocuments();
        } catch (Throwable $e) {
            $results['documents'] = [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
        
        return $results;
    }

    /**
     * Get transfer results
     */
    public function getResults(): array
    {
        return $this->transferResults;
    }

    /**
     * Transfer directory contents
     */
    private function transferDirectory(string $type, array $config): array
    {
        if (!($config['enabled'] ?? false)) {
            return [
                'success' => false,
                'message' => ucfirst($type) . '-Transfer ist deaktiviert',
                'skipped' => true,
            ];
        }
        
        $source = $config['source'] ?? '';
        $target = $config['target'] ?? '';
        
        if (empty($source) || empty($target)) {
            throw new AFS_ConfigurationException(ucfirst($type) . '-Quell- oder Zielpfad nicht konfiguriert');
        }
        
        if (!is_dir($source)) {
            throw new AFS_FileException("Quellverzeichnis nicht gefunden: {$source}");
        }
        
        // Ensure target directory exists
        if (!is_dir($target)) {
            if (!mkdir($target, 0755, true)) {
                throw new AFS_FileException("Zielverzeichnis konnte nicht erstellt werden: {$target}");
            }
        }
        
        // Transfer files
        $startTime = microtime(true);
        $stats = $this->copyDirectoryRecursive($source, $target);
        $duration = microtime(true) - $startTime;
        
        $result = [
            'success' => true,
            'source' => $source,
            'target' => $target,
            'files_copied' => $stats['files'],
            'directories_created' => $stats['dirs'],
            'total_size' => $stats['size'],
            'duration' => round($duration, 3),
            'timestamp' => date('Y-m-d H:i:s'),
        ];
        
        if (!empty($stats['errors'])) {
            $result['errors'] = $stats['errors'];
        }
        
        $this->logTransfer($type, $result);
        $this->transferResults[$type] = $result;
        
        return $result;
    }

    /**
     * Copy directory recursively
     */
    private function copyDirectoryRecursive(string $source, string $target): array
    {
        $stats = [
            'files' => 0,
            'dirs' => 0,
            'size' => 0,
            'errors' => [],
        ];
        
        $maxSize = $this->config['max_file_size'] ?? 104857600;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $targetPath = $target . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            
            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    if (mkdir($targetPath, 0755, true)) {
                        $stats['dirs']++;
                    } else {
                        $stats['errors'][] = "Verzeichnis konnte nicht erstellt werden: {$targetPath}";
                    }
                }
            } else {
                $fileSize = $item->getSize();
                
                // Check file size
                if ($fileSize > $maxSize) {
                    $stats['errors'][] = "Datei zu groß (übersprungen): {$item->getPathname()} ({$fileSize} Bytes)";
                    continue;
                }
                
                // Copy file
                if (copy($item->getPathname(), $targetPath)) {
                    $stats['files']++;
                    $stats['size'] += $fileSize;
                } else {
                    $stats['errors'][] = "Datei konnte nicht kopiert werden: {$item->getPathname()}";
                }
            }
        }
        
        return $stats;
    }

    /**
     * Log transfer operation
     */
    private function logTransfer(string $type, array $result): void
    {
        if (!($this->config['log_transfers'] ?? true)) {
            return;
        }
        
        if ($this->logger === null) {
            return;
        }
        
        $logData = [
            'type' => $type,
            'success' => $result['success'] ?? false,
            'duration' => $result['duration'] ?? 0,
            'timestamp' => $result['timestamp'] ?? date('Y-m-d H:i:s'),
        ];
        
        if ($type === 'database') {
            $logData['size'] = $result['size'] ?? 0;
        } else {
            $logData['files'] = $result['files_copied'] ?? 0;
            $logData['dirs'] = $result['directories_created'] ?? 0;
            $logData['size'] = $result['total_size'] ?? 0;
        }
        
        if (!empty($result['errors'])) {
            $logData['errors'] = count($result['errors']);
        }
        
        $this->logger->logMapping(
            'data_transfer',
            $logData,
            []
        );
    }
}
