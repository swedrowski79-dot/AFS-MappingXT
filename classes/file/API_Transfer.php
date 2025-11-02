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
    private ?array $imageColumnMap = null;
    private ?array $documentColumnMap = null;

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
        ];
        
        $maxSize = $this->transferConfig['max_file_size'] ?? 104857600;
        
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

        $map = $this->getImageColumnMap();

        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $filenameExpr = $this->db->quoteIdent($map['filename']);
        $storedFileExpr = $map['stored_file']
            ? $this->db->quoteIdent($map['stored_file'])
            : $filenameExpr;
        $storedPathExpr = $map['stored_path']
            ? $this->db->quoteIdent($map['stored_path'])
            : "''";
        $hashExpr = $map['hash'] ? $this->db->quoteIdent($map['hash']) : "''";
        $table = $this->db->quoteIdent($map['table']);
        $flagExpr = $this->db->quoteIdent($map['flag']);

        $sql = sprintf(
            'SELECT %s AS id, %s AS filename, %s AS stored_file, %s AS stored_path, %s AS hash
             FROM %s
             WHERE %s = :pending
             ORDER BY %s',
            $idExpr,
            $filenameExpr,
            $storedFileExpr,
            $storedPathExpr,
            $hashExpr,
            $table,
            $flagExpr,
            $idExpr
        );

        $rows = $this->db->fetchAll($sql, [':pending' => $map['flag_pending']]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'filename' => (string)$row['filename'],
                'stored_file' => $row['stored_file'] !== '' ? (string)$row['stored_file'] : (string)$row['filename'],
                'stored_path' => (string)($row['stored_path'] ?? ''),
                'hash' => $row['hash'] ?? null,
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

        $map = $this->getDocumentColumnMap();

        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $titleExpr = $this->db->quoteIdent($map['title']);
        $filenameExpr = $map['filename']
            ? $this->db->quoteIdent($map['filename'])
            : "''";
        $storedFileExpr = $map['stored_file']
            ? $this->db->quoteIdent($map['stored_file'])
            : $filenameExpr;
        $storedPathExpr = $map['stored_path']
            ? $this->db->quoteIdent($map['stored_path'])
            : "''";
        $hashExpr = $map['hash'] ? $this->db->quoteIdent($map['hash']) : "''";
        $table = $this->db->quoteIdent($map['table']);
        $flagExpr = $this->db->quoteIdent($map['flag']);

        $sql = sprintf(
            'SELECT %s AS id, %s AS title, %s AS filename, %s AS stored_file, %s AS stored_path, %s AS hash
             FROM %s
             WHERE %s = :pending
             ORDER BY %s',
            $idExpr,
            $titleExpr,
            $filenameExpr,
            $storedFileExpr,
            $storedPathExpr,
            $hashExpr,
            $table,
            $flagExpr,
            $idExpr
        );

        $rows = $this->db->fetchAll($sql, [':pending' => $map['flag_pending']]);
        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id' => (int)$row['id'],
                'title' => (string)$row['title'],
                'filename' => $row['filename'] !== '' ? (string)$row['filename'] : null,
                'stored_file' => $row['stored_file'] !== '' ? (string)$row['stored_file'] : ((string)$row['filename'] ?: (string)$row['title']),
                'stored_path' => (string)($row['stored_path'] ?? ''),
                'md5' => $row['hash'] ?? null,
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

        $map = $this->getImageColumnMap();
        $table = $this->db->quoteIdent($map['table']);
        $flagExpr = $this->db->quoteIdent($map['flag']);
        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $sql = sprintf('UPDATE %s SET %s = :cleared WHERE %s = :id', $table, $flagExpr, $idExpr);
        $rowsAffected = $this->db->execute($sql, [':cleared' => $map['flag_cleared'], ':id' => $imageId]);

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

        $map = $this->getDocumentColumnMap();
        $table = $this->db->quoteIdent($map['table']);
        $flagExpr = $this->db->quoteIdent($map['flag']);
        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $sql = sprintf('UPDATE %s SET %s = :cleared WHERE %s = :id', $table, $flagExpr, $idExpr);
        $rowsAffected = $this->db->execute($sql, [':cleared' => $map['flag_cleared'], ':id' => $documentId]);

        return $rowsAffected > 0;
    }

    /**
     * @return array<string,mixed>
     */
    private function getImageColumnMap(): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }
        if ($this->imageColumnMap !== null) {
            return $this->imageColumnMap;
        }

        $configMap = $this->transferConfig['pusher']['images'] ?? null;
        $tableName = is_array($configMap) && !empty($configMap['table'])
            ? (string)$configMap['table']
            : 'bilder';

        if (is_array($configMap)) {
            $map = $this->mapFromPusherConfig($configMap, [
                'id' => 'id_column',
                'filename' => 'filename_column',
                'stored_file' => 'stored_file_column',
                'stored_path' => 'stored_path_column',
                'hash' => 'hash_column',
                'flag' => 'flag_column',
            ]);
            $map['table'] = $tableName;
            return $this->imageColumnMap = $map;
        }

        $columns = $this->loadTableInfo($tableName);
        $map = $this->buildColumnMap($columns, [
            'id' => ['id', 'image_id'],
            'filename' => ['file_name', 'bildname', 'filename'],
            'stored_file' => ['stored_file', 'file_name', 'bildname'],
            'stored_path' => ['stored_path', 'path', 'dir'],
            'hash' => ['hash', 'md5'],
            'flag' => ['upload', 'uploaded'],
        ]);
        $map['id'] = $map['id'] ?? 'rowid';
        $map['flag'] = $map['flag'] ?? 'upload';
        if ($map['flag'] === 'upload') {
            $map['flag_pending'] = 1;
            $map['flag_cleared'] = 0;
        } else {
            $map['flag_pending'] = 0;
            $map['flag_cleared'] = 1;
        }
        $map['table'] = $tableName;
        return $this->imageColumnMap = $map;
    }

    /**
     * @return array<string,mixed>
     */
    private function getDocumentColumnMap(): array
    {
        if ($this->db === null) {
            throw new AFS_ConfigurationException('Datenbank-Verbindung nicht verfügbar');
        }
        if ($this->documentColumnMap !== null) {
            return $this->documentColumnMap;
        }

        $configMap = $this->transferConfig['pusher']['documents'] ?? null;
        $tableName = is_array($configMap) && !empty($configMap['table'])
            ? (string)$configMap['table']
            : 'dokumente';

        if (is_array($configMap)) {
            $map = $this->mapFromPusherConfig($configMap, [
                'id' => 'id_column',
                'title' => 'title_column',
                'filename' => 'filename_column',
                'stored_file' => 'stored_file_column',
                'stored_path' => 'stored_path_column',
                'hash' => 'hash_column',
                'flag' => 'flag_column',
            ]);
            $map['table'] = $tableName;
            $map['title'] = $map['title'] ?? 'title';
            return $this->documentColumnMap = $map;
        }

        $columns = $this->loadTableInfo($tableName);
        $map = $this->buildColumnMap($columns, [
            'id' => ['id', 'doc_id'],
            'title' => ['title', 'titel'],
            'filename' => ['file_name', 'dateiname', 'filename'],
            'stored_file' => ['stored_file', 'file_name', 'dateiname'],
            'stored_path' => ['stored_path', 'path', 'dir'],
            'hash' => ['hash', 'md5'],
            'flag' => ['upload', 'uploaded'],
        ]);
        $map['id'] = $map['id'] ?? 'rowid';
        $map['flag'] = $map['flag'] ?? 'upload';
        if ($map['flag'] === 'upload') {
            $map['flag_pending'] = 1;
            $map['flag_cleared'] = 0;
        } else {
            $map['flag_pending'] = 0;
            $map['flag_cleared'] = 1;
        }
        $map['title'] = $map['title'] ?? 'title';
        $map['table'] = $tableName;
        return $this->documentColumnMap = $map;
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @param array<string,array<int,string>> $candidates
     * @return array<string,string|null>
     */
    private function buildColumnMap(array $columns, array $candidates): array
    {
        $map = [];
        foreach ($candidates as $key => $names) {
            $map[$key] = $this->findColumn($columns, $names);
        }
        return $map;
    }

    private function mapFromPusherConfig(array $config, array $keys): array
    {
        $map = [];
        foreach ($keys as $target => $configKey) {
            $value = $config[$configKey] ?? null;
            $map[$target] = is_string($value) && $value !== '' ? $value : null;
        }
        $map['id'] = $map['id'] ?? 'rowid';
        $map['flag'] = $map['flag'] ?? 'upload';
        $map['flag_pending'] = isset($config['flag_pending']) ? (int)$config['flag_pending'] : ($map['flag'] === 'upload' ? 1 : 0);
        $map['flag_cleared'] = isset($config['flag_cleared']) ? (int)$config['flag_cleared'] : ($map['flag'] === 'upload' ? 0 : 1);
        return $map;
    }

    private function loadTableInfo(string $table): array
    {
        $quoted = '"' . str_replace('"', '""', $table) . '"';
        return $this->db->fetchAll('PRAGMA table_info(' . $quoted . ')');
    }

    /**
     * @param array<int,array<string,mixed>> $columns
     * @param array<int,string> $candidates
     */
    private function findColumn(array $columns, array $candidates): ?string
    {
        $lowerCandidates = array_map('strtolower', $candidates);
        foreach ($columns as $column) {
            $name = (string)($column['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $lower = strtolower($name);
            if (in_array($lower, $lowerCandidates, true)) {
                return $name;
            }
        }
        return null;
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
        $map = $this->getImageColumnMap();
        $table = $this->db->quoteIdent($map['table']);
        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $filenameExpr = $this->db->quoteIdent($map['filename']);
        $storedFileExpr = $map['stored_file']
            ? $this->db->quoteIdent($map['stored_file'])
            : $filenameExpr;
        $storedPathExpr = $map['stored_path']
            ? $this->db->quoteIdent($map['stored_path'])
            : "''";
        $flagExpr = $this->db->quoteIdent($map['flag']);

        $sql = sprintf(
            'SELECT %s AS id, %s AS filename, %s AS stored_file, %s AS stored_path
             FROM %s
             WHERE %s = :id AND %s = :pending
             LIMIT 1',
            $idExpr,
            $filenameExpr,
            $storedFileExpr,
            $storedPathExpr,
            $table,
            $idExpr,
            $flagExpr
        );

        $image = $this->db->fetchOne($sql, [
            ':id' => $imageId,
            ':pending' => $map['flag_pending'],
        ]);

        if (!$image) {
            return [
                'success' => false,
                'error' => 'Bild nicht gefunden oder bereits hochgeladen',
                'image_id' => $imageId,
            ];
        }

        $filename = (string)$image['filename'];
        $storedFile = (string)($image['stored_file'] ?? $filename);
        $storedPath = trim((string)($image['stored_path'] ?? ''), DIRECTORY_SEPARATOR);
        $relativePath = $storedPath !== '' ? $storedPath . DIRECTORY_SEPARATOR . $storedFile : $storedFile;
        $sourcePath = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
        $targetPath = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

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
            'filename' => $storedFile,
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
        $map = $this->getDocumentColumnMap();
        $table = $this->db->quoteIdent($map['table']);
        $idExpr = $map['id'] === 'rowid' ? 'rowid' : $this->db->quoteIdent($map['id']);
        $titleExpr = $this->db->quoteIdent($map['title']);
        $filenameExpr = $map['filename']
            ? $this->db->quoteIdent($map['filename'])
            : "''";
        $storedFileExpr = $map['stored_file']
            ? $this->db->quoteIdent($map['stored_file'])
            : $filenameExpr;
        $storedPathExpr = $map['stored_path']
            ? $this->db->quoteIdent($map['stored_path'])
            : "''";
        $flagExpr = $this->db->quoteIdent($map['flag']);

        $sql = sprintf(
            'SELECT %s AS id, %s AS title, %s AS filename, %s AS stored_file, %s AS stored_path
             FROM %s
             WHERE %s = :id AND %s = :pending
             LIMIT 1',
            $idExpr,
            $titleExpr,
            $filenameExpr,
            $storedFileExpr,
            $storedPathExpr,
            $table,
            $idExpr,
            $flagExpr
        );
        $document = $this->db->fetchOne($sql, [
            ':id' => $documentId,
            ':pending' => $map['flag_pending'],
        ]);

        if (!$document) {
            return [
                'success' => false,
                'error' => 'Dokument nicht gefunden oder bereits hochgeladen',
                'document_id' => $documentId,
            ];
        }

        $filename = $document['filename'] !== '' ? (string)$document['filename'] : (string)$document['title'];
        if ($filename === '') {
            return [
                'success' => false,
                'error' => 'Dateiname nicht gefunden',
                'document_id' => $documentId,
            ];
        }

        $storedFile = (string)($document['stored_file'] ?? $filename);
        $storedPath = trim((string)($document['stored_path'] ?? ''), DIRECTORY_SEPARATOR);
        $relativePath = $storedPath !== '' ? $storedPath . DIRECTORY_SEPARATOR . $storedFile : $storedFile;
        $sourcePath = rtrim($source, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
        $targetPath = rtrim($target, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;

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
            'title' => $document['title'] ?? $filename,
            'filename' => $storedFile,
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
