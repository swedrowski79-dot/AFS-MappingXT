<?php
declare(strict_types=1);

/**
 * AFS_FileException
 *
 * Exception thrown when file operations fail.
 * Used for:
 * - File not found
 * - Directory creation failures
 * - File copy/move failures
 * - Permission errors
 * - File size validation errors
 */
class AFS_FileException extends RuntimeException
{
}
