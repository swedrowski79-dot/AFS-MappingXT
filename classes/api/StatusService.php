<?php
declare(strict_types=1);

class StatusService
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function get(array $config): array
    {
        try {
            $tracker = createStatusTracker($config, 'mapping');
            return $tracker->getStatus();
        } catch (Throwable $e) {
            return [
                'state' => 'error',
                'stage' => null,
                'message' => $e->getMessage(),
                'total' => 0,
                'processed' => 0,
                'started_at' => null,
                'updated_at' => null,
                'finished_at' => null,
            ];
        }
    }
}
