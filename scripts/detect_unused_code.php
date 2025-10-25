#!/usr/bin/env php
<?php
/**
 * Dead Code Detection Tool
 * 
 * Automatisches Erkennen von nicht genutzten Klassen und Funktionen
 * im AFS-MappingXT Projekt.
 * 
 * Analysiert:
 * - Klassen in /classes
 * - Methoden in diesen Klassen
 * - Verwendung in PHP-Dateien im gesamten Projekt
 * 
 * Usage: php scripts/detect_unused_code.php [--verbose] [--json]
 */
declare(strict_types=1);

class DeadCodeDetector
{
    private string $projectRoot;
    private array $classes = [];
    private array $methods = [];
    private array $classUsages = [];
    private array $methodUsages = [];
    private bool $verbose = false;
    
    // Verzeichnisse die übersprungen werden sollen
    private array $excludeDirs = ['.git', 'vendor', 'node_modules', 'docs'];
    
    // Klassen die als "Einstiegspunkte" gelten (immer als verwendet markiert)
    private array $entryPointClasses = [
        'AFS_DatabaseException',
        'AFS_SyncBusyException',
        'AFS_ValidationException',
        'AFS_ConfigurationException'
    ];
    
    public function __construct(string $projectRoot, bool $verbose = false)
    {
        $this->projectRoot = rtrim($projectRoot, '/');
        $this->verbose = $verbose;
    }
    
    /**
     * Führt die vollständige Dead-Code-Analyse durch
     */
    public function analyze(): array
    {
        $this->log("🔍 Starte Dead-Code-Analyse...\n");
        
        // Schritt 1: Alle Klassen und Methoden sammeln
        $this->log("📋 Schritt 1: Klassen und Methoden sammeln...");
        $this->collectClassesAndMethods();
        $this->log(sprintf(" ✓ %d Klassen gefunden\n", count($this->classes)));
        
        // Schritt 2: Verwendungen analysieren
        $this->log("🔎 Schritt 2: Verwendungen analysieren...");
        $this->analyzeUsages();
        $this->log(" ✓ Analyse abgeschlossen\n");
        
        // Schritt 3: Nicht verwendete Code-Elemente identifizieren
        $this->log("📊 Schritt 3: Nicht verwendete Elemente identifizieren...\n");
        $results = $this->identifyUnused();
        
        return $results;
    }
    
    /**
     * Sammelt alle Klassen und ihre Methoden aus /classes
     */
    private function collectClassesAndMethods(): void
    {
        $classesDir = $this->projectRoot . '/classes';
        
        if (!is_dir($classesDir)) {
            throw new RuntimeException("Classes-Verzeichnis nicht gefunden: {$classesDir}");
        }
        
        $files = glob($classesDir . '/*.php');
        
        foreach ($files as $file) {
            $this->parseClassFile($file);
        }
    }
    
    /**
     * Parst eine Klassendatei und extrahiert Klassen- und Methodennamen
     */
    private function parseClassFile(string $file): void
    {
        $content = file_get_contents($file);
        $basename = basename($file);
        
        // Remove comments to avoid false positives
        $contentNoComments = preg_replace([
            '#/\*.*?\*/#s',  // Multi-line comments
            '#//.*$#m'        // Single-line comments
        ], '', $content);
        
        // Klasse finden (class ClassName) - ohne Kommentare
        if (preg_match('/^(?:abstract\s+|final\s+)?class\s+([A-Z_][A-Za-z0-9_]*)/im', $contentNoComments, $match)) {
            $className = $match[1];
            $this->classes[$className] = [
                'file' => $file,
                'basename' => $basename
            ];
            
            // Methoden der Klasse finden
            $this->methods[$className] = [];
            
            // Public/protected/private Methoden
            preg_match_all(
                '/^\s+(public|protected|private)\s+(?:static\s+)?function\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/im',
                $contentNoComments,
                $methodMatches,
                PREG_SET_ORDER
            );
            
            foreach ($methodMatches as $methodMatch) {
                $visibility = $methodMatch[1];
                $methodName = $methodMatch[2];
                
                // Konstruktoren und Magic Methods werden nicht als "unused" gemeldet
                $isSpecial = in_array($methodName, [
                    '__construct', '__destruct', '__call', '__callStatic',
                    '__get', '__set', '__isset', '__unset', '__toString',
                    '__invoke', '__clone', '__debugInfo', '__serialize', '__unserialize'
                ]);
                
                $this->methods[$className][$methodName] = [
                    'visibility' => $visibility,
                    'special' => $isSpecial
                ];
            }
        }
    }
    
    /**
     * Analysiert die Verwendung von Klassen und Methoden im gesamten Projekt
     */
    private function analyzeUsages(): void
    {
        $phpFiles = $this->findPhpFiles($this->projectRoot);
        
        foreach ($phpFiles as $file) {
            $this->analyzeFileUsages($file);
        }
        
        // Entry-Point-Klassen als verwendet markieren (Exceptions etc.)
        foreach ($this->entryPointClasses as $className) {
            if (isset($this->classes[$className])) {
                $this->classUsages[$className] = ['entry_point' => true];
            }
        }
    }
    
    /**
     * Analysiert eine einzelne Datei auf Klassen- und Methodenverwendung
     */
    private function analyzeFileUsages(string $file): void
    {
        $content = file_get_contents($file);
        $basename = basename($file);
        
        // Klassen-Verwendungen finden
        foreach (array_keys($this->classes) as $className) {
            // new ClassName
            if (preg_match('/\bnew\s+' . preg_quote($className) . '\b/', $content)) {
                $this->classUsages[$className][] = $basename;
            }
            // ClassName::method()
            if (preg_match('/\b' . preg_quote($className) . '::/i', $content)) {
                $this->classUsages[$className][] = $basename;
            }
            // extends ClassName
            if (preg_match('/\bextends\s+' . preg_quote($className) . '\b/', $content)) {
                $this->classUsages[$className][] = $basename;
            }
            // throw new ClassName
            if (preg_match('/\bthrow\s+new\s+' . preg_quote($className) . '\b/', $content)) {
                $this->classUsages[$className][] = $basename;
            }
            // catch (ClassName $e)
            if (preg_match('/\bcatch\s*\(\s*' . preg_quote($className) . '\s+\$/i', $content)) {
                $this->classUsages[$className][] = $basename;
            }
        }
        
        // Methoden-Verwendungen finden
        foreach ($this->methods as $className => $methods) {
            foreach (array_keys($methods) as $methodName) {
                // $obj->methodName()
                if (preg_match('/->' . preg_quote($methodName) . '\s*\(/i', $content)) {
                    $this->methodUsages[$className][$methodName][] = $basename;
                }
                // ClassName::methodName()
                if (preg_match('/\b' . preg_quote($className) . '::' . preg_quote($methodName) . '\s*\(/i', $content)) {
                    $this->methodUsages[$className][$methodName][] = $basename;
                }
            }
        }
    }
    
    /**
     * Findet alle PHP-Dateien im Projekt
     */
    private function findPhpFiles(string $dir): array
    {
        $phpFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $path = $file->getPathname();
                
                // Prüfen ob in exclude-Dir
                $skip = false;
                foreach ($this->excludeDirs as $excludeDir) {
                    if (strpos($path, '/' . $excludeDir . '/') !== false) {
                        $skip = true;
                        break;
                    }
                }
                
                if (!$skip) {
                    $phpFiles[] = $path;
                }
            }
        }
        
        return $phpFiles;
    }
    
    /**
     * Identifiziert nicht verwendete Klassen und Methoden
     */
    private function identifyUnused(): array
    {
        $unusedClasses = [];
        $unusedMethods = [];
        $statistics = [
            'total_classes' => count($this->classes),
            'total_methods' => 0,
            'unused_classes' => 0,
            'unused_methods' => 0
        ];
        
        // Nicht verwendete Klassen
        foreach (array_keys($this->classes) as $className) {
            if (!isset($this->classUsages[$className]) || empty($this->classUsages[$className])) {
                $unusedClasses[] = [
                    'class' => $className,
                    'file' => $this->classes[$className]['basename']
                ];
                $statistics['unused_classes']++;
            }
        }
        
        // Nicht verwendete Methoden
        foreach ($this->methods as $className => $methods) {
            foreach ($methods as $methodName => $methodInfo) {
                $statistics['total_methods']++;
                
                // Skip special methods und private methods in used classes
                if ($methodInfo['special']) {
                    continue;
                }
                
                // Wenn die Klasse nicht verwendet wird, überspringen wir die Methodenprüfung
                if (!isset($this->classUsages[$className]) || empty($this->classUsages[$className])) {
                    continue;
                }
                
                // Prüfen ob Methode verwendet wird
                $isUsed = isset($this->methodUsages[$className][$methodName]) 
                    && !empty($this->methodUsages[$className][$methodName]);
                
                if (!$isUsed && $methodInfo['visibility'] === 'public') {
                    $unusedMethods[] = [
                        'class' => $className,
                        'method' => $methodName,
                        'visibility' => $methodInfo['visibility'],
                        'file' => $this->classes[$className]['basename']
                    ];
                    $statistics['unused_methods']++;
                }
            }
        }
        
        return [
            'statistics' => $statistics,
            'unused_classes' => $unusedClasses,
            'unused_methods' => $unusedMethods
        ];
    }
    
    /**
     * Gibt eine Nachricht aus (wenn verbose aktiviert)
     */
    private function log(string $message): void
    {
        if ($this->verbose) {
            echo $message;
        }
    }
}

// CLI Ausführung
if (PHP_SAPI === 'cli') {
    $options = getopt('', ['verbose', 'json', 'help']);
    
    if (isset($options['help'])) {
        echo <<<'HELP'
Dead Code Detection Tool - AFS-MappingXT

Usage: php scripts/detect_unused_code.php [OPTIONS]

Options:
  --verbose    Ausführliche Ausgabe während der Analyse
  --json       Ausgabe im JSON-Format
  --help       Diese Hilfe anzeigen

Beschreibung:
  Analysiert den Code auf nicht verwendete Klassen und Methoden.
  
  Das Tool:
  - Scannt alle Klassen in /classes
  - Analysiert alle PHP-Dateien im Projekt
  - Identifiziert nicht verwendete Klassen und öffentliche Methoden
  
Hinweise:
  - Exception-Klassen werden als "Entry-Points" betrachtet
  - Magic Methods (__construct, __toString, etc.) werden nicht gemeldet
  - Private/Protected Methoden werden nur in nicht verwendeten Klassen geprüft

HELP;
        exit(0);
    }
    
    $verbose = isset($options['verbose']);
    $jsonOutput = isset($options['json']);
    
    $projectRoot = dirname(__DIR__);
    $detector = new DeadCodeDetector($projectRoot, $verbose);
    
    try {
        $results = $detector->analyze();
        
        if ($jsonOutput) {
            echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            echo "\n";
        } else {
            // Formatierte Ausgabe
            echo "\n";
            echo "=" . str_repeat("=", 78) . "\n";
            echo "  DEAD CODE DETECTION REPORT\n";
            echo "=" . str_repeat("=", 78) . "\n\n";
            
            echo "📊 Statistik:\n";
            echo "  • Klassen gesamt:          {$results['statistics']['total_classes']}\n";
            echo "  • Methoden gesamt:         {$results['statistics']['total_methods']}\n";
            echo "  • Nicht verwendete Klassen: {$results['statistics']['unused_classes']}\n";
            echo "  • Nicht verwendete Methoden: {$results['statistics']['unused_methods']}\n";
            echo "\n";
            
            if (!empty($results['unused_classes'])) {
                echo "🔴 Nicht verwendete Klassen:\n";
                echo str_repeat("-", 79) . "\n";
                foreach ($results['unused_classes'] as $item) {
                    echo sprintf("  • %s\n    Datei: %s\n", $item['class'], $item['file']);
                }
                echo "\n";
            } else {
                echo "✅ Alle Klassen werden verwendet!\n\n";
            }
            
            if (!empty($results['unused_methods'])) {
                echo "🟡 Nicht verwendete öffentliche Methoden:\n";
                echo str_repeat("-", 79) . "\n";
                
                // Gruppieren nach Klasse
                $methodsByClass = [];
                foreach ($results['unused_methods'] as $item) {
                    $methodsByClass[$item['class']][] = $item;
                }
                
                foreach ($methodsByClass as $className => $methods) {
                    echo "  📦 {$className} ({$methods[0]['file']}):\n";
                    foreach ($methods as $method) {
                        echo "     • {$method['method']}()\n";
                    }
                }
                echo "\n";
            } else {
                echo "✅ Alle öffentlichen Methoden werden verwendet!\n\n";
            }
            
            echo "=" . str_repeat("=", 78) . "\n";
            
            if ($results['statistics']['unused_classes'] > 0 || $results['statistics']['unused_methods'] > 0) {
                echo "⚠️  Es wurden nicht verwendete Code-Elemente gefunden.\n";
                echo "   Bitte überprüfen Sie, ob diese entfernt werden können.\n";
                exit(1);
            } else {
                echo "✅ Keine nicht verwendeten Code-Elemente gefunden!\n";
                exit(0);
            }
        }
    } catch (Exception $e) {
        fwrite(STDERR, "❌ Fehler: {$e->getMessage()}\n");
        exit(1);
    }
}
