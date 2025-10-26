<?php
declare(strict_types=1);

/**
 * AFS_DatabaseException
 *
 * Exception thrown when database operations fail.
 * Used for:
 * - Database connection failures
 * - SQL execution errors
 * - Transaction failures
 * - Database file not found
 */
class AFS_DatabaseException extends RuntimeException
{
}
