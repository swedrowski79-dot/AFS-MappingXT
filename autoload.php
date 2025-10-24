<?php
// autoload.php – simpler Loader für /classes und /src
declare(strict_types=1);

spl_autoload_register(function ($class) {
    // Handle classes in /classes directory (no namespace)
    $baseDir = __DIR__ . '/classes/';
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }
    
    // Handle namespaced classes in /src directory
    if (strpos($class, '\\') !== false) {
        $srcDir = __DIR__ . '/src/';
        $file = $srcDir . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
