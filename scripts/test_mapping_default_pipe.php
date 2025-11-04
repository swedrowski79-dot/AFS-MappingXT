<?php
declare(strict_types=1);

/**
 * Unit tests for the default pipe enhancement in MappingExpressionEvaluator
 * 
 * Tests the following scenarios:
 * 1. default:'Text' → returns 'Text' when value is empty
 * 2. default:afs.Warengruppe.Bezeichnung → uses dynamic field value
 * 3. default:$func.concat(afs.Warengruppe.Bezeichnung, ' Hier ist die Welt') → evaluates function expression
 * 4. Combination with | trim | null_if_empty
 */

require_once dirname(__DIR__) . '/autoload.php';

class MappingDefaultPipeTest
{
    private MappingExpressionEvaluator $evaluator;
    private int $passed = 0;
    private int $failed = 0;
    
    public function __construct()
    {
        $this->evaluator = new MappingExpressionEvaluator();
    }
    
    public function run(): void
    {
        echo "=== Testing MappingExpressionEvaluator default pipe ===\n\n";
        
        $this->testStaticDefault();
        $this->testStaticDefaultWithValue();
        $this->testDynamicFieldDefault();
        $this->testFunctionDefault();
        $this->testDefaultWithTrimAndNullIfEmpty();
        $this->testDefaultWithQuotedString();
        $this->testDefaultWithEmptyString();
        $this->testDefaultWithNullValue();
        $this->testComplexExpression();
        
        echo "\n=== Test Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total: " . ($this->passed + $this->failed) . "\n";
        
        if ($this->failed > 0) {
            exit(1);
        }
    }
    
    private function testStaticDefault(): void
    {
        echo "Test 1: default:'Text' with empty value\n";
        
        $context = ['afs' => ['Warengruppe' => ['Bezeichnung' => '']]];
        $expression = "afs.Warengruppe.Bezeichnung | default:'Fallback Text'";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Fallback Text', $result, "Should return fallback text when value is empty");
    }
    
    private function testStaticDefaultWithValue(): void
    {
        echo "Test 2: default:'Text' with non-empty value\n";
        
        $context = ['afs' => ['Warengruppe' => ['Bezeichnung' => 'Werkzeuge']]];
        $expression = "afs.Warengruppe.Bezeichnung | default:'Fallback Text'";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Werkzeuge', $result, "Should return original value when not empty");
    }
    
    private function testDynamicFieldDefault(): void
    {
        echo "Test 3: default:afs.Warengruppe.Bezeichnung with dynamic field\n";
        
        $context = [
            'afs' => [
                'Warengruppe' => ['Bezeichnung' => 'Schrauben'],
                'Artikel' => ['Name' => '']
            ]
        ];
        $expression = "afs.Artikel.Name | default:afs.Warengruppe.Bezeichnung";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Schrauben', $result, "Should use dynamic field value as fallback");
    }
    
    private function testFunctionDefault(): void
    {
        echo "Test 4: default:\$func.concat(...) with function expression\n";
        
        $context = [
            'afs' => [
                'Warengruppe' => ['Bezeichnung' => 'Werkzeuge'],
                'Kategorie' => ['Name' => '']
            ]
        ];
        $expression = "afs.Kategorie.Name | default:\$func.concat(afs.Warengruppe.Bezeichnung, ' Hier ist die Welt')";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Werkzeuge Hier ist die Welt', $result, "Should evaluate function expression as fallback");
    }
    
    private function testDefaultWithTrimAndNullIfEmpty(): void
    {
        echo "Test 5: Combination with | trim | null_if_empty\n";
        
        $context = [
            'afs' => [
                'Warengruppe' => ['Bezeichnung' => 'Schrauben'],
                'Artikel' => ['Name' => '   ']
            ]
        ];
        $expression = "afs.Artikel.Name | trim | null_if_empty | default:\$func.concat(afs.Warengruppe.Bezeichnung, ' Hier ist die Welt')";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Schrauben Hier ist die Welt', $result, "Should work with trim and null_if_empty pipes");
    }
    
    private function testDefaultWithQuotedString(): void
    {
        echo "Test 6: default with quoted string containing special chars\n";
        
        $context = ['value' => null];
        $expression = "value | default:'Default: Value'";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Default: Value', $result, "Should handle quoted strings with special characters");
    }
    
    private function testDefaultWithEmptyString(): void
    {
        echo "Test 7: default with empty string value\n";
        
        $context = ['value' => ''];
        $expression = "value | default:'Not Empty'";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Not Empty', $result, "Should trigger default for empty string");
    }
    
    private function testDefaultWithNullValue(): void
    {
        echo "Test 8: default with null value\n";
        
        $context = ['value' => null];
        $expression = "value | default:'Null Fallback'";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Null Fallback', $result, "Should trigger default for null value");
    }
    
    private function testComplexExpression(): void
    {
        echo "Test 9: Complex expression from user example\n";
        
        $context = [
            'afs' => [
                'Warengruppe' => ['Bezeichnung' => 'Elektrowerkzeuge']
            ],
            'evo' => [
                'category' => ['name' => '  ']
            ]
        ];
        $expression = "evo.category.name | trim | null_if_empty | default:\$func.concat(afs.Warengruppe.Bezeichnung, ' Hier ist die Welt')";
        $result = $this->evaluator->evaluate($expression, $context);
        
        $this->assertEquals('Elektrowerkzeuge Hier ist die Welt', $result, "Should handle complete user example correctly");
    }
    
    private function assertEquals($expected, $actual, string $message): void
    {
        if ($expected === $actual) {
            echo "  ✓ PASS: $message\n";
            echo "    Expected: " . var_export($expected, true) . "\n";
            echo "    Got:      " . var_export($actual, true) . "\n\n";
            $this->passed++;
        } else {
            echo "  ✗ FAIL: $message\n";
            echo "    Expected: " . var_export($expected, true) . "\n";
            echo "    Got:      " . var_export($actual, true) . "\n\n";
            $this->failed++;
        }
    }
}

// Run tests
try {
    $test = new MappingDefaultPipeTest();
    $test->run();
} catch (Throwable $e) {
    echo "ERROR: Test execution failed\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
