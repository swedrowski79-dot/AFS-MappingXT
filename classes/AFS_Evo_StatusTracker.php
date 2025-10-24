<?php

class AFS_Evo_StatusTracker
{
    private PDO $db;
    private string $job;
    private int $maxErrors;

    public function __construct(string $statusDbPath, string $job = 'categories', int $maxErrors = 200)
    {
        $this->job = $job;
        $this->maxErrors = max(1, $maxErrors);

        $this->db = new PDO('sqlite:' . $statusDbPath);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->ensureJobRow();
    }

    public function begin(string $stage, string $message = ''): void
    {
        $this->updateStatus([
            'state' => 'running',
            'stage' => $stage,
            'message' => $message,
            'processed' => 0,
            'total' => 0,
            'started_at' => date('c'),
            'finished_at' => null,
        ]);
    }

    public function advance(string $stage, array $data = []): void
    {
        $payload = array_merge(['stage' => $stage, 'state' => 'running'], $data);
        $this->updateStatus($payload);
    }

    public function complete(array $data = []): void
    {
        $payload = array_merge([
            'state' => 'ready',
            'stage' => null,
            'message' => 'Sync abgeschlossen',
            'finished_at' => date('c'),
        ], $data);
        $this->updateStatus($payload);
    }

    public function fail(string $message, ?string $stage = null): void
    {
        $this->updateStatus([
            'state' => 'error',
            'message' => $message,
            'stage' => $stage,
            'finished_at' => date('c'),
        ]);
    }

    public function updateStatus(array $data): void
    {
        if ($data === []) {
            return;
        }

        $data['updated_at'] = date('c');

        $cols = [];
        $params = [];
        foreach ($data as $column => $value) {
            $cols[] = $this->quoteIdent($column) . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        $params[':job'] = $this->job;

        $sql = sprintf(
            'UPDATE %s SET %s WHERE job = :job',
            $this->quoteIdent('sync_status'),
            implode(', ', $cols)
        );

        $this->db->prepare($sql)->execute($params);
    }

    public function logEvent(string $message, array $context = [], ?string $stage = null, string $level = 'info'): void
    {
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare(
                'INSERT INTO sync_log (job, level, stage, message, context, created_at)
                 VALUES (:job, :level, :stage, :message, :context, :created_at)'
            );
            $stmt->execute([
                ':job' => $this->job,
                ':level' => strtolower($level),
                ':stage' => $stage,
                ':message' => $message,
                ':context' => $context === [] ? null : json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':created_at' => date('c'),
            ]);

            if ($this->maxErrors > 0) {
                $this->enforceErrorLimit();
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function logError(string $message, array $context = [], ?string $stage = null): void
    {
        $this->logEvent($message, $context, $stage, 'error');
    }

    public function logWarning(string $message, array $context = [], ?string $stage = null): void
    {
        $this->logEvent($message, $context, $stage, 'warning');
    }

    public function logInfo(string $message, array $context = [], ?string $stage = null): void
    {
        $this->logEvent($message, $context, $stage, 'info');
    }

    public function getStatus(): array
    {
        $stmt = $this->db->prepare('SELECT * FROM sync_status WHERE job = :job');
        $stmt->execute([':job' => $this->job]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: [
            'job' => $this->job,
            'total' => 0,
            'processed' => 0,
            'state' => 'idle',
            'stage' => null,
            'message' => null,
            'started_at' => null,
            'updated_at' => null,
            'finished_at' => null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getLogs(int $limit = 100, ?array $levels = null): array
    {
        $sql = 'SELECT id, level, stage, message, context, created_at FROM sync_log WHERE job = :job';
        $params = [':job' => $this->job, ':limit' => $limit];

        if ($levels !== null && $levels !== []) {
            $placeholders = [];
            foreach ($levels as $idx => $level) {
                $ph = ':level' . $idx;
                $placeholders[] = $ph;
                $params[$ph] = strtolower((string)$level);
            }
            $sql .= ' AND level IN (' . implode(',', $placeholders) . ')';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key === ':limit') {
                $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
            } elseif (str_starts_with($key, ':level')) {
                $stmt->bindValue($key, (string)$value, PDO::PARAM_STR);
            } else {
                $stmt->bindValue($key, $value, PDO::PARAM_STR);
            }
        }
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            if (!empty($row['context'])) {
                $decoded = json_decode($row['context'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $row['context'] = $decoded;
                }
            } else {
                $row['context'] = null;
            }
        }
        unset($row);

        return $rows;
    }

    public function getErrors(int $limit = 100): array
    {
        return $this->getLogs($limit, ['error']);
    }

    public function clearLog(): void
    {
        $stmt = $this->db->prepare('DELETE FROM sync_log WHERE job = :job');
        $stmt->execute([':job' => $this->job]);
    }

    public function clearErrors(): void
    {
        $this->clearLog();
    }

    private function enforceErrorLimit(): void
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) AS cnt FROM sync_log WHERE job = :job'
        );
        $stmt->execute([':job' => $this->job]);
        $count = (int)($stmt->fetchColumn() ?: 0);

        if ($count <= $this->maxErrors) {
            return;
        }

        $deleteCount = $count - $this->maxErrors;
        if ($deleteCount <= 0) {
            return;
        }
        $stmt = $this->db->prepare(
            'DELETE FROM sync_log
             WHERE id IN (
                 SELECT id FROM sync_log
                 WHERE job = :job
                 ORDER BY created_at ASC
                 LIMIT :limit
             )'
        );
        $stmt->bindValue(':job', $this->job, PDO::PARAM_STR);
        $stmt->bindValue(':limit', $deleteCount, PDO::PARAM_INT);
        $stmt->execute();
    }

    private function ensureJobRow(): void
    {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO sync_status (job) VALUES (:job)');
        $stmt->execute([':job' => $this->job]);
        $stmt = $this->db->prepare(
            "UPDATE sync_status
             SET state = CASE
                 WHEN state IS NULL OR state = '' THEN 'ready'
                 WHEN state = 'done' THEN 'ready'
                 ELSE state
             END
             WHERE job = :job"
        );
        $stmt->execute([':job' => $this->job]);
    }

    private function quoteIdent(string $ident): string
    {
        return '"' . str_replace('"', '""', $ident) . '"';
    }
}
