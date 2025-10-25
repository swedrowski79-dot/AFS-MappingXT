<?php
declare(strict_types=1);

/**
 * AFS_ConfigurationException
 *
 * Exception thrown when configuration or YAML parsing errors occur.
 * Used for:
 * - Missing configuration files
 * - Invalid YAML syntax
 * - Missing required configuration keys
 * - Invalid configuration values
 */
class AFS_ConfigurationException extends RuntimeException
{
}
