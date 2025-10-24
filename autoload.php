<?php
// autoload.php – simpler Loader für /classes
declare(strict_types=1);

spl_autoload_register(function ($class) {
    $baseDir = __DIR__ . '/classes/';
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
