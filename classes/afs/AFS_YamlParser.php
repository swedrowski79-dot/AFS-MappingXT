<?php
/**
 * AFS_YamlParser - Simple YAML Parser for Configuration Files
 * 
 * A lightweight YAML parser that handles the subset of YAML features
 * used in AFS-MappingXT configuration files. This eliminates the need
 * for the php-yaml extension.
 * 
 * Supported features:
 * - Maps (key: value)
 * - Nested structures (indentation-based)
 * - Strings (quoted and unquoted)
 * - Numbers (integers and floats)
 * - Booleans (true/false, yes/no)
 * - Null values (null, ~)
 * - Lists (- item syntax)
 * - Comments (# comment)
 * - Multi-line strings (basic support)
 * 
 * Not supported (not needed for this project):
 * - Anchors and aliases
 * - Complex multi-line strings (|, >)
 * - Tags and custom types
 * - Documents (---)
 */
class AFS_YamlParser
{
    /**
     * Parse a YAML string into a PHP array
     * 
     * @param string $yaml YAML content to parse
     * @return array Parsed data structure
     * @throws AFS_ConfigurationException if parsing fails
     */
    public static function parse(string $yaml): array
    {
        $lines = explode("\n", $yaml);
        $result = [];
        $stack = [&$result];
        $indents = [-1];
        $listMode = [false];
        
        foreach ($lines as $lineNumber => $line) {
            // Remove comments
            $commentPos = strpos($line, '#');
            if ($commentPos !== false) {
                $line = substr($line, 0, $commentPos);
            }
            
            // Skip empty lines
            if (trim($line) === '') {
                continue;
            }
            
            // Calculate indentation
            $indent = strlen($line) - strlen(ltrim($line));
            $trimmed = trim($line);
            
            // Adjust stack based on indentation - pop until we find the right level
            while (count($indents) > 1 && $indent <= $indents[count($indents) - 1]) {
                array_pop($stack);
                array_pop($indents);
                array_pop($listMode);
            }
            
            // Handle list items
            if (preg_match('/^-\s+(.+)$/', $trimmed, $matches)) {
                $value = self::parseValue(trim($matches[1]));
                
                $current = &$stack[count($stack) - 1];
                if (!is_array($current)) {
                    throw new AFS_ConfigurationException("Invalid YAML structure at line " . ($lineNumber + 1));
                }
                
                $current[] = $value;
                continue;
            }
            
            // Handle key-value pairs
            $colonPos = strpos($trimmed, ':');
            if ($colonPos === false) {
                continue;
            }
            
            $key = trim(substr($trimmed, 0, $colonPos));
            $valueStr = trim(substr($trimmed, $colonPos + 1));
            
            $current = &$stack[count($stack) - 1];
            
            // If value is empty, this is a nested structure
            if ($valueStr === '') {
                $current[$key] = [];
                $stack[] = &$current[$key];
                $indents[] = $indent;
                $listMode[] = false;
            } else {
                // Parse the value
                $current[$key] = self::parseValue($valueStr);
            }
        }
        
        return $result;
    }
    
    /**
     * Parse a YAML file into a PHP array
     * 
     * @param string $filename Path to YAML file
     * @return array Parsed data structure
     * @throws AFS_ConfigurationException if file cannot be read or parsed
     */
    public static function parseFile(string $filename): array
    {
        if (!is_file($filename)) {
            throw new AFS_ConfigurationException("YAML file not found: {$filename}");
        }
        
        $content = file_get_contents($filename);
        if ($content === false) {
            throw new AFS_ConfigurationException("Failed to read YAML file: {$filename}");
        }
        
        return self::parse($content);
    }
    
    /**
     * Parse a scalar YAML value into the appropriate PHP type
     * 
     * @param string $value String value to parse
     * @return mixed Parsed value (string, int, float, bool, null, array)
     */
    private static function parseValue(string $value)
    {
        // Handle inline arrays [item1, item2, item3]
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $arrayContent = substr($value, 1, -1);
            if (trim($arrayContent) === '') {
                return [];
            }
            
            $items = array_map('trim', explode(',', $arrayContent));
            return array_map([self::class, 'parseValue'], $items);
        }
        
        // Remove quotes if present
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
            (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return substr($value, 1, -1);
        }
        
        // Check for null
        if ($value === 'null' || $value === '~' || $value === '') {
            return null;
        }
        
        // Check for boolean
        $lowerValue = strtolower($value);
        if ($lowerValue === 'true' || $lowerValue === 'yes') {
            return true;
        }
        if ($lowerValue === 'false' || $lowerValue === 'no') {
            return false;
        }
        
        // Check for number
        if (is_numeric($value)) {
            if (strpos($value, '.') !== false) {
                return (float) $value;
            }
            return (int) $value;
        }
        
        // Return as string
        return $value;
    }
}
