<?php
declare(strict_types=1);

class SQLite_Connection
{
    private ?PDO $pdo = null;
    private string $databasePath;
    private int $queryTimeout;

    public function __construct(
        string $databasePath,
        array $options = []
    ) {
        $this->databasePath = $databasePath;
        $this->queryTimeout = (int)($options['query_timeout'] ?? 30);

        $this->connect();
    }

    private function connect(): void
    {
        try {
            $this->pdo = new PDO('sqlite:' . $this->databasePath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable foreign keys
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            
            // Performance optimizations
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
            $this->pdo->exec('PRAGMA temp_store = MEMORY');
            $this->pdo->exec('PRAGMA cache_size = -64000'); // 64MB cache
            
            // Set busy timeout
            $this->pdo->setAttribute(PDO::ATTR_TIMEOUT, $this->queryTimeout);
        } catch (PDOException $e) {
            throw new AFS_DatabaseException('SQLite connection failed: ' . $e->getMessage());
        }
    }

    /**
     * Get the underlying PDO instance
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return $this->pdo;
    }

    /**
     * Execute a parametrized query
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new AFS_DatabaseException('Query failed: ' . $e->getMessage() . ' | SQL: ' . $sql);
        }
    }

    /**
     * Fetch all rows from a query
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Fetch a single row
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = $this->query($sql, $params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Fetch a single scalar value (first column of first row)
     */
    public function scalar(string $sql, array $params = []): mixed
    {
        $stmt = $this->query($sql, $params);
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : null;
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     */
    public function execute(string $sql, array $params = []): int
    {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * Get the last insert ID
     */
    public function lastInsertId(): int
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit a transaction
     */
    public function commit(): bool
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return $this->pdo->commit();
    }

    /**
     * Rollback a transaction
     */
    public function rollback(): bool
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return $this->pdo->rollBack();
    }

    /**
     * Check if in a transaction
     */
    public function inTransaction(): bool
    {
        if ($this->pdo === null) {
            return false;
        }
        return $this->pdo->inTransaction();
    }

    /**
     * Quote identifier (table or column name)
     */
    public function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }

    /**
     * Quote a string value (use parametrized queries instead when possible)
     */
    public function quote(string $value): string
    {
        if ($this->pdo === null) {
            throw new AFS_DatabaseException('Database connection is closed');
        }
        return $this->pdo->quote($value);
    }

    /**
     * Close the database connection
     */
    public function close(): void
    {
        $this->pdo = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
