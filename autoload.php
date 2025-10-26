<?php
// autoload.php – simpler Loader für /classes mit Unterordnern
declare(strict_types=1);

spl_autoload_register(function ($class) {
    // Handle classes in /classes directory and subdirectories (no namespace)
    $baseDir = __DIR__ . '/classes/';
    
    // Try direct class file in /classes
    $file = $baseDir . str_replace('\\', '/', $class) . '.php';
    if (is_file($file)) {
        require $file;
        return;
    }
    
    // Try subdirectories: afs, mssql, mapping, evo, sqlite, mysql, file, status, xt, security
    $subdirs = ['afs', 'mssql', 'mapping', 'evo', 'sqlite', 'mysql', 'file', 'status', 'xt', 'security'];
    foreach ($subdirs as $subdir) {
        $file = $baseDir . $subdir . '/' . str_replace('\\', '/', $class) . '.php';
        if (is_file($file)) {
            require $file;
            return;
        }
    }
});
