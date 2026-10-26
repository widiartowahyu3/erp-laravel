<?php

declare(strict_types=1);

/**
 * Scan PHP code using PHP token_get_all to detect dangerous function calls,
 * execution operators, and non-literal SQL statement arguments.
 *
 * @return array<int, string> List of violation descriptions
 */
function scanPhpTokens(string $code, string $filePath = 'code'): array
{
    $tokens = token_get_all($code);
    $violations = [];

    $dangerousFunctions = [
        'eval',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
        'popen',
        'unserialize',
        'assert',
        'create_function',
        'extract',
        'dd',
        'dump',
        'var_dump',
    ];

    $rawSqlMethods = [
        'raw',
        'whereraw',
        'selectraw',
        'orderbyraw',
        'havingraw',
        'groupbyraw',
        'fromraw',
        'unprepared',
        'statement',
    ];

    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // 1. Backtick execution operator
        if ($token === '`') {
            $violations[] = "Backtick execution operator used in [{$filePath}]";

            continue;
        }

        // 2. Language construct: eval
        if (is_array($token) && $token[0] === T_EVAL) {
            $violations[] = "eval() construct used at line {$token[2]} in [{$filePath}]";

            continue;
        }

        if (! is_array($token)) {
            continue;
        }

        // 3. Identifiers / strings
        if ($token[0] === T_STRING) {
            $name = strtolower($token[1]);

            // Dangerous function calls
            if (in_array($name, $dangerousFunctions, true)) {
                // Check previous non-whitespace token
                $prev = null;
                for ($p = $i - 1; $p >= 0; $p--) {
                    if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $prev = $tokens[$p];
                    break;
                }

                $isMethodOrDecl = false;
                if ($prev !== null && is_array($prev)) {
                    if (in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST], true)) {
                        $isMethodOrDecl = true;
                    }
                }

                if (! $isMethodOrDecl) {
                    // Check next non-whitespace token
                    $next = null;
                    for ($n = $i + 1; $n < $count; $n++) {
                        if (is_array($tokens[$n]) && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        $next = $tokens[$n];
                        break;
                    }

                    if ($next === '(') {
                        $violations[] = "Prohibited function call [{$token[1]}()] at line {$token[2]} in [{$filePath}]";
                    }
                }
            }

            // Raw SQL methods
            if (in_array($name, $rawSqlMethods, true)) {
                $prev = null;
                for ($p = $i - 1; $p >= 0; $p--) {
                    if (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $prev = $tokens[$p];
                    break;
                }

                $isMethod = false;
                if ($prev !== null && is_array($prev)) {
                    if (in_array($prev[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                        $isMethod = true;
                    }
                }

                if ($isMethod) {
                    $openParenIndex = null;
                    for ($n = $i + 1; $n < $count; $n++) {
                        if (is_array($tokens[$n]) && in_array($tokens[$n][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        if ($tokens[$n] === '(') {
                            $openParenIndex = $n;
                        }
                        break;
                    }

                    if ($openParenIndex !== null) {
                        $argTokens = [];
                        $depth = 0;
                        for ($k = $openParenIndex + 1; $k < $count; $k++) {
                            $t = $tokens[$k];
                            if ($t === '(' || $t === '[' || $t === '{') {
                                $depth++;
                                $argTokens[] = $t;

                                continue;
                            }
                            if ($t === ')' || $t === ']' || $t === '}') {
                                if ($depth === 0) {
                                    break;
                                }
                                $depth--;
                                $argTokens[] = $t;

                                continue;
                            }
                            if ($t === ',' && $depth === 0) {
                                break;
                            }
                            $argTokens[] = $t;
                        }

                        $significant = array_values(array_filter($argTokens, function ($t): bool {
                            if (is_array($t)) {
                                return ! in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
                            }

                            return true;
                        }));

                        $isPureLiteral = (count($significant) === 1 && is_array($significant[0]) && $significant[0][0] === T_CONSTANT_ENCAPSED_STRING);
                        if (! $isPureLiteral) {
                            $violations[] = "Non-literal SQL expression in [{$token[1]}()] at line {$token[2]} in [{$filePath}]";
                        }
                    }
                }
            }
        }
    }

    return $violations;
}

test('static security scanner verifies app, routes, database, config, and bootstrap have zero violations', function () {
    $directories = [
        app_path(),
        base_path('routes'),
        database_path(),
        config_path(),
        base_path('bootstrap'),
    ];

    $allViolations = [];
    $scannedFilesCount = 0;

    foreach ($directories as $directory) {
        if (! is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $filePath = $file->getPathname();

                // Skip cache directory under bootstrap if present
                if (str_contains($filePath, 'bootstrap/cache')) {
                    continue;
                }

                $code = file_get_contents($filePath);
                if ($code === false) {
                    continue;
                }

                $scannedFilesCount++;
                $violations = scanPhpTokens($code, $filePath);
                if (! empty($violations)) {
                    $allViolations = array_merge($allViolations, $violations);
                }
            }
        }
    }

    expect($scannedFilesCount)->toBeGreaterThan(5);
    expect($allViolations)->toBeEmpty('Static security scan found prohibited calls: '.implode("\n", $allViolations));
});

test('static security scanner detects dangerous function calls and operators (negative tests)', function (string $fixtureCode, string $expectedViolationSubstr) {
    $violations = scanPhpTokens($fixtureCode, 'negative_test_fixture.php');

    expect($violations)->not->toBeEmpty('Expected scanner to detect violation in fixture: '.$fixtureCode);

    $matched = false;
    foreach ($violations as $v) {
        if (str_contains($v, $expectedViolationSubstr)) {
            $matched = true;
            break;
        }
    }
    expect($matched)->toBeTrue("Expected violation containing [{$expectedViolationSubstr}], got: ".implode(', ', $violations));
})->with([
    ['<?php exec("ls");', 'exec()'],
    ['<?php shell_exec("ls");', 'shell_exec()'],
    ['<?php system("ls");', 'system()'],
    ['<?php passthru("ls");', 'passthru()'],
    ['<?php proc_open("ls", [], $pipes);', 'proc_open()'],
    ['<?php popen("ls", "r");', 'popen()'],
    ['<?php eval("return 1;");', 'eval()'],
    ['<?php unserialize($data);', 'unserialize()'],
    ['<?php assert($condition);', 'assert()'],
    ['<?php create_function("", "return 1;");', 'create_function()'],
    ['<?php extract($data);', 'extract()'],
    ['<?php dd($var);', 'dd()'],
    ['<?php dump($var);', 'dump()'],
    ['<?php var_dump($var);', 'var_dump()'],
    ['<?php $out = `ls`;', 'Backtick'],
    ['<?php DB::statement("CREATE TABLE " . $table);', 'statement()'],
    ['<?php DB::raw("SELECT * FROM " . $table);', 'raw()'],
    ['<?php DB::unprepared($dynamicSql);', 'unprepared()'],
    ['<?php $query->whereRaw("id = {$id}");', 'whereRaw()'],
    ['<?php $query->selectRaw($selectCols);', 'selectRaw()'],
    ['<?php $query->orderByRaw($order . " ASC");', 'orderByRaw()'],
    ['<?php $query->havingRaw("count > " . $min);', 'havingRaw()'],
    ['<?php $query->groupByRaw($groupByCol);', 'groupByRaw()'],
    ['<?php $query->fromRaw($customFrom);', 'fromRaw()'],
]);

test('static security scanner allows safe static literals and valid method calls', function () {
    $safeSnippets = [
        '<?php $worker->exec();',
        '<?php DB::statement("CREATE TABLE units (id INT)");',
        '<?php DB::raw("COUNT(*)");',
        '<?php $query->whereRaw("status = ?", ["active"]);',
        '<?php DB::unprepared("CREATE TRIGGER tr_audit BEFORE UPDATE ON audit_logs FOR EACH ROW BEGIN SIGNAL SQLSTATE \'45000\'; END;");',
    ];

    foreach ($safeSnippets as $code) {
        $violations = scanPhpTokens($code, 'safe_fixture.php');
        expect($violations)->toBeEmpty("Safe snippet erroneously flagged: {$code}. Violations: ".implode(', ', $violations));
    }
});
