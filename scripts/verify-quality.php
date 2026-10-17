<?php

declare(strict_types=1);

/**
 * Development tooling for the standalone Fast Forward Changelog package.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see https://github.com/php-fast-forward/changelog
 */

/**
 * Exercises development gates with disposable files and subprocesses.
 *
 * This check is an isolated integration check, separate from the unit suite.
 */
$root = sys_get_temp_dir() . '/changelog-quality-' . bin2hex(random_bytes(8));

if (! mkdir($root . '/src', 0700, true)) {
    throw new RuntimeException('Unable to create an isolated quality fixture.');
}

$source = $root . '/src/Fixture.php';
$report = $root . '/coverage.xml';
$coverageScript = dirname(__DIR__) . '/tests/verify-coverage.php';
$phpdocScript = __DIR__ . '/phpdoc.php';

/** @return array{0: int, 1: string} Exit status and combined diagnostic output. */
$run = static function (array $arguments): array {
    $process = proc_open([PHP_BINARY, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, []);

    if (false === $process) {
        throw new RuntimeException('Unable to start a quality verifier.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $output];
};

$checks = 0;

try {
    $plainSource = "<?php\nclass Fixture {\n    /** Returns the fixture value without observable side effects. */\n    public function value(): int\n    {\n        return 1;\n    }\n}\n";
    file_put_contents($source, $plainSource);
    $valid = '<coverage lines-valid="1" lines-covered="1"><packages><package><classes><class name="Fixture" line-rate="1"><lines><line number="6" hits="1"/></lines></class></classes></package></packages></coverage>';
    $cases = [
        'complete coverage' => [$valid, '100', 0],
        'uncovered line below threshold' => [
            str_replace([
                'lines-covered="1"', 'line-rate="1"', 'hits="1"'],
                ['lines-covered="0"', 'line-rate="0"', 'hits="0"'],
                $valid,
            ),
            '100',
            1,
        ],
        'empty report' => ['', '100', 1],
        'malformed report' => ['<coverage>', '100', 1],
        'invalid threshold' => [$valid, 'NaN', 1],
        'threshold above 100' => [$valid, '101', 1],
        'invalid report count' => [str_replace('lines-valid="1"', 'lines-valid="invalid"', $valid), '100', 1],
        'zero denominator' => [
            str_replace(['lines-valid="1"', 'lines-covered="1"'], ['lines-valid="0"', 'lines-covered="0"'], $valid),
            '100',
            1,
        ],
        'impossible numerator' => [str_replace('lines-covered="1"', 'lines-covered="2"', $valid), '100', 1],
        'uncovered class with full root totals' => [str_replace('line-rate="1"', 'line-rate="0"', $valid), '100', 1],
        'uncovered own line with full rates' => [str_replace('hits="1"', 'hits="0"', $valid), '100', 1],
        'invalid own line hits' => [str_replace('hits="1"', 'hits="invalid"', $valid), '100', 1],
    ];

    foreach ($cases as $label => [$xml, $threshold, $expected]) {
        file_put_contents($report, $xml);
        [$exit, $output] = $run([$coverageScript, $report, $threshold]);
        if ($exit !== $expected) {
            throw new RuntimeException(sprintf(
                'Coverage case "%s" returned %d, expected %d: %s',
                $label,
                $exit,
                $expected,
                $output,
            ));
        }
        ++$checks;
    }

    [$exit, $output] = $run([$coverageScript, $root . '/missing.xml', '100']);
    if (1 !== $exit || ! str_contains($output, 'readable Cobertura report')) {
        throw new RuntimeException('Coverage must reject a missing report.');
    }
    ++$checks;

    /**
     * Places authored DocBlock lines before a method in disposable fixture source.
     *
     * @param list<string> $lines       Summary and tag lines exercised through the real CLI.
     * @param string       $declaration Complete method declaration, including its body.
     *
     * @return string PHP source for a single isolated fixture class.
     */
    $fixture = static function (
        array $lines,
        string $declaration = 'public function value(): int { return 1; }',
    ): string {
        $docBlock = "/**\n * " . implode("\n * ", $lines) . "\n */";

        return "<?php\nclass Fixture {\n" . $docBlock . "\n" . $declaration . "\n}\n";
    };
    $summary = 'Returns the fixture value without observable side effects.';
    $return = '@return int The immutable fixture value.';
    $phpdocCases = [
        'described result' => [$fixture([$summary, '', $return]), 0, 'contracts verified'],
        'interface method contract' => [
            str_replace(
                'class Fixture',
                'interface Fixture',
                $fixture([$summary, '', $return], 'public function value(): int;'),
            ),
            0,
            'contracts verified',
        ],
        'promoted constructor parameter' => [
            $fixture([
                'Stores the configured fixture value during construction.',
                '',
                '@param int $value Initial immutable fixture value.',
            ], 'public function __construct(public readonly int $value) {}'),
            0,
            'contracts verified',
        ],
        'constructor without return' => [
            $fixture([
                'Initializes the fixture with the configured integer value.',
                '',
                '@param int $value Initial value supplied by the caller.',
            ], 'public function __construct(int $value) {}'),
            0,
            'contracts verified',
        ],
        'destructor without return' => [
            $fixture(['Releases fixture resources without returning a result.'], 'public function __destruct() {}'),
            0,
            'contracts verified',
        ],
        'described void' => [
            $fixture([
                'Performs the fixture operation without returning a value.',
                '',
                '@return void Completes the operation without a result.',
            ], 'public function value(): void {}'),
            0,
            'contracts verified',
        ],
        'multiline shapes and descriptions' => [
            $fixture([
                'Extracts record data without modifying the supplied fields.',
                '',
                '@param array{',
                '    id: int,',
                '    labels: list<non-empty-string>,',
                '} $record Input record with stable field names.',
                '    Labels retain their original ordering.',
                '',
                '@return array{',
                '    id: int,',
                '} The extracted identifier, preserving its integer type.',
                '',
                '@see https://example.invalid/record Fixture format reference.',
            ], 'public function value(array $record): array { return []; }'),
            0,
            'contracts verified',
        ],
        'closure nested variables and generics' => [
            $fixture([
                'Invokes a callback with a stable key and returns its records.',
                '',
                '@param Closure(string $key): array<string, list<int>> $factory Record factory invoked by the operation.',
                '@return array<string, list<int>> Records returned by the supplied factory.',
            ], 'public function value(Closure $factory): array { return []; }'),
            0,
            'contracts verified',
        ],
        'variadic references and nullable union' => [
            $fixture([
                'Reads optional fixture values without discarding their declared types.',
                '',
                '@param int|string|null &...$values Values supplied by reference in caller order.',
                '@return int The number of values inspected by the operation.',
            ], 'public function value(int|string|null &...$values): int { return 0; }'),
            0,
            'contracts verified',
        ],
        'missing docblock' => [
            '<?php class Fixture { public function value(): int { return 1; } }',
            1,
            'explanatory contract summary',
        ],
        'tags without summary' => [$fixture([$return]), 1, 'explanatory contract summary'],
        'inheritance boilerplate' => [$fixture(['{@inheritdoc}', $return]), 1, 'explanatory contract summary'],
        'method name as summary' => [$fixture(['value', $return]), 1, 'explanatory contract summary'],
        'long method name as summary' => [
            $fixture([
                'returnsTheFixtureValueWithoutSideEffects',
                $return,
            ], 'public function returnsTheFixtureValueWithoutSideEffects(): int { return 1; }'),
            1,
            'explanatory contract summary',
        ],
        'invalid PHP' => ['<?php class Broken {', 1, 'Syntax error'],
        'source without methods' => ['<?php return 1;', 1, 'No production methods were inspected'],
        'private methods require contracts' => [
            $fixture([
                $summary,
                $return,
            ], 'public function value(): int { return 1; } private function hidden(): void {}'),
            1,
            'hidden() requires an explanatory contract summary',
        ],
        'missing return' => [$fixture([$summary]), 1, 'exactly one @return'],
        'return without description' => [
            $fixture([$summary, '@return int']),
            1,
            '@return requires an explanatory description',
        ],
        'invalid return type' => [
            $fixture([$summary, '@return array{ Broken type.']),
            1,
            'valid @return type description syntax',
        ],
        'duplicate returns' => [$fixture([$summary, $return, $return]), 1, 'exactly one @return'],
        'void still requires return' => [
            $fixture([$summary], 'public function value(): void {}'),
            1,
            'exactly one @return',
        ],
        'missing parameter' => [
            $fixture([$summary, $return], 'public function value(int $input): int { return 1; }'),
            1,
            'exactly one @param for $input; found 0',
        ],
        'unknown parameter name' => [
            $fixture([
                $summary,
                '@param int $other Caller value.',
                $return,
            ], 'public function value(int $input): int { return 1; }'),
            1,
            'unknown parameter $other',
        ],
        'duplicate parameters' => [
            $fixture([
                $summary,
                '@param int $input Caller value.',
                '@param int $input Duplicate caller value.',
                $return,
            ], 'public function value(int $input): int { return 1; }'),
            1,
            'exactly one @param for $input; found 2',
        ],
        'parameter without type' => [
            $fixture([
                $summary,
                '@param $input Caller value.',
                $return,
            ], 'public function value(int $input): int { return 1; }'),
            1,
            'valid @param type $name description syntax',
        ],
        'parameter without description' => [
            $fixture([$summary, '@param int $input', $return], 'public function value(int $input): int { return 1; }'),
            1,
            '@param $input requires an explanatory description',
        ],
        'malformed parameter shape' => [
            $fixture([
                $summary,
                '@param array{ $input Caller value.',
                $return,
            ], 'public function value(array $input): int { return 1; }'),
            1,
            'valid @param type $name description syntax',
        ],
        'parameter on an empty signature' => [
            $fixture([$summary, '@param int $input Caller value.', $return]),
            1,
            'unknown parameter $input',
        ],
        'see tag cannot describe a parameter' => [
            $fixture([
                $summary,
                '@param int $input',
                '@see https://example.invalid/record Caller documentation.',
                $return,
            ], 'public function value(int $input): int { return 1; }'),
            1,
            '@param $input requires an explanatory description',
        ],
        'see tag cannot describe a return' => [
            $fixture([$summary, '@return int', '@see https://example.invalid/record Return documentation.']),
            1,
            '@return requires an explanatory description',
        ],
        'tool-specific tags cannot replace public tags' => [
            $fixture([
                $summary,
                '@phpstan-param int $input Caller value.',
                $return,
            ], 'public function value(int $input): int { return 1; }'),
            1,
            'exactly one @param for $input; found 0',
        ],
    ];

    foreach ($phpdocCases as $label => [$php, $expected, $diagnostic]) {
        file_put_contents($source, $php);
        [$exit, $output] = $run([$phpdocScript, $source]);

        if ($exit !== $expected || ! str_contains($output, $diagnostic)) {
            throw new RuntimeException(sprintf(
                'PHPDoc case "%s" returned %d, expected %d with diagnostic "%s": %s',
                $label,
                $exit,
                $expected,
                $diagnostic,
                $output,
            ));
        }

        ++$checks;
    }

    fwrite(STDOUT, sprintf("Development gates passed %d isolated success and rejection cases.\n", $checks));
} finally {
    foreach ([$report, $source] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($root . '/src');
    rmdir($root);
}
