<?php
declare(strict_types=1);

/**
 * AFS_SyncBusyException
 *
 * Exception thrown when a synchronization is already running.
 * Used for:
 * - Preventing concurrent synchronization runs
 * - Indicating the system is busy with another sync operation
 * - Protecting against race conditions in sync state
 */
class AFS_SyncBusyException extends RuntimeException
{
}

