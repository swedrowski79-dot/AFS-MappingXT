<?php
declare(strict_types=1);

/**
 * AFS_ValidationException
 *
 * Exception thrown when data validation fails.
 * Used for:
 * - Invalid method arguments
 * - Data type mismatches
 * - Missing required data
 * - Data constraint violations
 */
class AFS_ValidationException extends InvalidArgumentException
{
}
