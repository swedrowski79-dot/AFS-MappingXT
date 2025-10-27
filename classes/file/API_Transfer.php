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
    private array $transferConfig;
    private array $appConfig;
    private ?STATUS_MappingLogger $logger;
    private array $transferResults = [];
    private ?SQLite_Connection $db = null;

    public function __construct(array $config, ?STATUS_MappingLogger $logger = null, ?SQLite_Connection $db = null)
    {
        $this->appConfig = $config;
        $this->transferConfig = $config['data_transfer'] ?? [];
        $this->logger = $logger;
        $this->db = $db;
        
        // Validate configuration
        if (empty($this->transferConfig['api_key'])) {
            throw new AFS_ConfigurationException('DATA_TRANSFER_API_KEY ist nicht konfiguriert');
        }
    }

    /**
     * Validate API key
     */
    public function validateApiKey(string $providedKey): bool
    {
        $configuredKey = $this->transferConfig['api_key'] ?? '';
        
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
        $dbConfig = $this->transferConfig['database'] ?? [];
        
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
        $maxSize = $this->transferConfig['max_file_size'] ?? 104857600;
        
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
        return $this->transferDirectory('images');
    }

    /**
     * Transfer documents from source to target directory
     */
    public function transferDocuments(): array
    {
        return $this->transferDirectory('documents');
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
     * Resolve media directory configuration for transfers.
     *
     * @param string $type images|documents
     * @return array{enabled: bool, source: string, target: string}
     */
    private function resolveDirectoryConfig(string $type): array
    {
        $transferSection = $this->transferConfig[$type] ?? [];
        $enabled = (bool)($transferSection['enabled'] ?? false);

        // Support legacy configuration format with direct source/target in transfer section
        if (isset($transferSection['source']) && isset($transferSection['target'])) {
            return [
                'enabled' => $enabled,
                'source' => $transferSection['source'],
                'target' => $transferSection['target'],
            ];
        }

        $mediaKey = $type === 'images' ? 'images' : 'documents';
        $mediaConfig = $this->appConfig['paths']['media'][$mediaKey] ?? [];

        // Optional override per transfer section
        $pathOverride = $transferSection['path'] ?? null;

        $source = $pathOverride ?: ($mediaConfig['source'] ?? '');
        $target = $pathOverride ?: ($mediaConfig['target'] ?? ($mediaConfig['source'] ?? ''));

        if ($source === '') {
            throw new AFS_ConfigurationException(ucfirst($type) . '-Pfad (Quelle) ist nicht konfiguriert');
        }

        if ($target === '') {
            $target = $source;
        }

        return [
            'enabled' => $enabled,
            'source' => $source,
            'target' => $target,
        ];
    }

    /**
     * Transfer directory contents
     */
    private function transferDirectory(string $type): array
    {
        $dirConfig = $this->resolveDirectoryConfig($type);

        if (!$dirConfig['enabled']) {
            return [
                'success' => false,
                'message' => ucfirst($type) . '-Transfer ist deaktiviert',
                'skipped' => true,
            ];
        }

        $source = $dirConfig['source'];
        $target = $dirConfig['target'];

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
            'skipped' => 0,
        ];
        
        $maxSize = $this->transferConfig['max_file_size'] ?? 104857600;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $subPathName = $iterator->getSubPathName();
            
            // Skip "logs" directory and its contents
            if ($this->shouldSkipPath($subPathName)) {
                $stats['skipped']++;
                continue;
            }
            
            $targetPath = $target . DIRECTORY_SEPARATOR . $subPathName;
            
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
     * Check if a path should be skipped during transfer
     * 
     * @param string $path Relative path from source directory
     * @return bool True if path should be skipped
     */
    private function shouldSkipPath(string $path): bool
    {
        // Normalize path separators
        $normalizedPath = str_replace('\\', '/', $path);
        
        // Split path into components
        $pathParts = explode('/', $normalizedPath);
        
        // Skip if any path component is "logs" (case-insensitive)
        foreach ($pathParts as $part) {
            if (strcasecmp($part, 'logs') === 0) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Log transfer operation
     */
    private function logTransfer(string $type, array $result): void
    {
        if (!($this->transferConfig['log_transfers'] ?? true)) {
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

    /**
     * Get list of images that need to be uploaded (uploaded = 0)
     * 
     * @return array List of images with ID and Bildname
     */
    public function getPendingImages(): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $sql = 'SELECT ID, Bildname, md5 FROM Bilder WHERE uploaded = 0 ORDER BY ID';
        $result = [];
        
        $stmt = $this->db->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int)$row['ID'],
                'filename' => (string)$row['Bildname'],
                'md5' => $row['md5'] ?? null,
            ];
        }
        
        return $result;
    }

    /**
     * Get list of documents that need to be uploaded (uploaded = 0)
     * 
     * @return array List of documents with ID, Titel, and Dateiname
     */
    public function getPendingDocuments(): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $sql = 'SELECT ID, Titel, Dateiname, md5 FROM Dokumente WHERE uploaded = 0 ORDER BY ID';
        $result = [];
        
        $stmt = $this->db->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[] = [
                'id' => (int)$row['ID'],
                'title' => (string)$row['Titel'],
                'filename' => $row['Dateiname'] ?? null,
                'md5' => $row['md5'] ?? null,
            ];
        }
        
        return $result;
    }

    /**
     * Mark an image as uploaded
     * 
     * @param int $imageId The ID of the image
     * @return bool True if successful
     */
    public function markImageAsUploaded(int $imageId): bool
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $sql = 'UPDATE Bilder SET uploaded = 1 WHERE ID = ?';
        $rowsAffected = $this->db->execute($sql, [$imageId]);
        
        return $rowsAffected > 0;
    }

    /**
     * Mark a document as uploaded
     * 
     * @param int $documentId The ID of the document
     * @return bool True if successful
     */
    public function markDocumentAsUploaded(int $documentId): bool
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $sql = 'UPDATE Dokumente SET uploaded = 1 WHERE ID = ?';
        $rowsAffected = $this->db->execute($sql, [$documentId]);
        
        return $rowsAffected > 0;
    }

    /**
     * Transfer a single image file
     * 
     * @param int $imageId The ID of the image to transfer
     * @return array Transfer result
     */
    public function transferSingleImage(int $imageId): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $dirConfig = $this->resolveDirectoryConfig('images');
        if (!$dirConfig['enabled']) {
            return [
                'success' => false,
                'message' => 'Bilder-Transfer ist deaktiviert',
                'skipped' => true,
            ];
        }

        $source = $dirConfig['source'];
        $target = $dirConfig['target'];

        // Get image info from database
        $sql = 'SELECT ID, Bildname, md5 FROM Bilder WHERE ID = ? AND uploaded = 0';
        $image = $this->db->fetchOne($sql, [$imageId]);

        if (!$image) {
            return [
                'success' => false,
                'error' => 'Bild nicht gefunden oder bereits hochgeladen',
                'image_id' => $imageId,
            ];
        }

        $filename = (string)$image['Bildname'];
        $sourcePath = $source . DIRECTORY_SEPARATOR . $filename;
        $targetPath = $target . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($sourcePath)) {
            return [
                'success' => false,
                'error' => 'Quelldatei nicht gefunden',
                'image_id' => $imageId,
                'filename' => $filename,
            ];
        }

        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new AFS_FileException("Zielverzeichnis konnte nicht erstellt werden: {$targetDir}");
            }
        }

        // Check file size
        $fileSize = filesize($sourcePath);
        $maxSize = $this->transferConfig['max_file_size'] ?? 104857600;
        
        if ($fileSize > $maxSize) {
            return [
                'success' => false,
                'error' => 'Datei ist zu groß',
                'image_id' => $imageId,
                'filename' => $filename,
                'size' => $fileSize,
                'max_size' => $maxSize,
            ];
        }

        // Copy file
        $startTime = microtime(true);
        $success = copy($sourcePath, $targetPath);
        $duration = microtime(true) - $startTime;

        if (!$success) {
            return [
                'success' => false,
                'error' => 'Datei konnte nicht kopiert werden',
                'image_id' => $imageId,
                'filename' => $filename,
            ];
        }

        // Mark as uploaded
        $this->markImageAsUploaded($imageId);

        $result = [
            'success' => true,
            'image_id' => $imageId,
            'filename' => $filename,
            'size' => $fileSize,
            'duration' => round($duration, 3),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->logTransfer('image_single', $result);

        return $result;
    }

    /**
     * Transfer a single document file
     * 
     * @param int $documentId The ID of the document to transfer
     * @return array Transfer result
     */
    public function transferSingleDocument(int $documentId): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }

        $dirConfig = $this->resolveDirectoryConfig('documents');
        if (!$dirConfig['enabled']) {
            return [
                'success' => false,
                'message' => 'Dokumente-Transfer ist deaktiviert',
                'skipped' => true,
            ];
        }

        $source = $dirConfig['source'];
        $target = $dirConfig['target'];

        // Get document info from database
        $sql = 'SELECT ID, Titel, Dateiname, md5 FROM Dokumente WHERE ID = ? AND uploaded = 0';
        $document = $this->db->fetchOne($sql, [$documentId]);

        if (!$document) {
            return [
                'success' => false,
                'error' => 'Dokument nicht gefunden oder bereits hochgeladen',
                'document_id' => $documentId,
            ];
        }

        $filename = $document['Dateiname'] ?? $document['Titel'];
        if (empty($filename)) {
            return [
                'success' => false,
                'error' => 'Dateiname nicht gefunden',
                'document_id' => $documentId,
            ];
        }

        // Ensure PDF extension
        if (!str_contains($filename, '.')) {
            $filename .= '.pdf';
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $filename;
        $targetPath = $target . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($sourcePath)) {
            return [
                'success' => false,
                'error' => 'Quelldatei nicht gefunden',
                'document_id' => $documentId,
                'filename' => $filename,
            ];
        }

        // Ensure target directory exists
        $targetDir = dirname($targetPath);
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new AFS_FileException("Zielverzeichnis konnte nicht erstellt werden: {$targetDir}");
            }
        }

        // Check file size
        $fileSize = filesize($sourcePath);
        $maxSize = $this->transferConfig['max_file_size'] ?? 104857600;
        
        if ($fileSize > $maxSize) {
            return [
                'success' => false,
                'error' => 'Datei ist zu groß',
                'document_id' => $documentId,
                'filename' => $filename,
                'size' => $fileSize,
                'max_size' => $maxSize,
            ];
        }

        // Copy file
        $startTime = microtime(true);
        $success = copy($sourcePath, $targetPath);
        $duration = microtime(true) - $startTime;

        if (!$success) {
            return [
                'success' => false,
                'error' => 'Datei konnte nicht kopiert werden',
                'document_id' => $documentId,
                'filename' => $filename,
            ];
        }

        // Mark as uploaded
        $this->markDocumentAsUploaded($documentId);

        $result = [
            'success' => true,
            'document_id' => $documentId,
            'title' => (string)$document['Titel'],
            'filename' => $filename,
            'size' => $fileSize,
            'duration' => round($duration, 3),
            'timestamp' => date('Y-m-d H:i:s'),
        ];

        $this->logTransfer('document_single', $result);

        return $result;
    }

    /**
     * Transfer all pending images (uploaded = 0) individually
     * 
     * @return array Transfer results with success/failed counts
     */
    public function transferPendingImages(): array
    {
        $startTime = microtime(true);
        $pendingImages = $this->getPendingImages();
        
        $results = [
            'success' => true,
            'total' => count($pendingImages),
            'transferred' => 0,
            'failed' => 0,
            'skipped' => 0,
            'files' => [],
            'errors' => [],
        ];

        foreach ($pendingImages as $image) {
            $result = $this->transferSingleImage($image['id']);
            
            if ($result['success']) {
                $results['transferred']++;
                $results['files'][] = $image['filename'];
            } elseif ($result['skipped'] ?? false) {
                $results['skipped']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $image['id'],
                    'filename' => $image['filename'],
                    'error' => $result['error'] ?? 'Unbekannter Fehler',
                ];
            }
        }

        $results['duration'] = round(microtime(true) - $startTime, 3);
        $results['timestamp'] = date('Y-m-d H:i:s');

        $this->logTransfer('images_pending', $results);

        return $results;
    }

    /**
     * Transfer all pending documents (uploaded = 0) individually
     * 
     * @return array Transfer results with success/failed counts
     */
    public function transferPendingDocuments(): array
    {
        $startTime = microtime(true);
        $pendingDocuments = $this->getPendingDocuments();
        
        $results = [
            'success' => true,
            'total' => count($pendingDocuments),
            'transferred' => 0,
            'failed' => 0,
            'skipped' => 0,
            'files' => [],
            'errors' => [],
        ];

        foreach ($pendingDocuments as $document) {
            $result = $this->transferSingleDocument($document['id']);
            
            if ($result['success']) {
                $results['transferred']++;
                $results['files'][] = $document['filename'] ?? $document['title'];
            } elseif ($result['skipped'] ?? false) {
                $results['skipped']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'id' => $document['id'],
                    'title' => $document['title'],
                    'filename' => $document['filename'],
                    'error' => $result['error'] ?? 'Unbekannter Fehler',
                ];
            }
        }

        $results['duration'] = round(microtime(true) - $startTime, 3);
        $results['timestamp'] = date('Y-m-d H:i:s');

        $this->logTransfer('documents_pending', $results);

        return $results;
    }
}
