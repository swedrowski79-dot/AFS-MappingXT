<?php
declare(strict_types=1);

/**
 * Thrown when a synchronization is already running to prevent concurrent runs.
 */
class AFS_SyncBusyException extends RuntimeException {}
