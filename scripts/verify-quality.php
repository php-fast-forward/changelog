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
        'uncovered line below threshold' => [str_replace(['lines-covered="1"', 'line-rate="1"', 'hits="1"'], ['lines-covered="0"', 'line-rate="0"', 'hits="0"'], $valid), '100', 1],
        'empty report' => ['', '100', 1],
        'malformed report' => ['<coverage>', '100', 1],
        'invalid threshold' => [$valid, 'NaN', 1],
        'threshold above 100' => [$valid, '101', 1],
        'invalid report count' => [str_replace('lines-valid="1"', 'lines-valid="invalid"', $valid), '100', 1],
        'zero denominator' => [str_replace(['lines-valid="1"', 'lines-covered="1"'], ['lines-valid="0"', 'lines-covered="0"'], $valid), '100', 1],
        'impossible numerator' => [str_replace('lines-covered="1"', 'lines-covered="2"', $valid), '100', 1],
        'uncovered class with full root totals' => [str_replace('line-rate="1"', 'line-rate="0"', $valid), '100', 1],
        'uncovered own line with full rates' => [str_replace('hits="1"', 'hits="0"', $valid), '100', 1],
        'invalid own line hits' => [str_replace('hits="1"', 'hits="invalid"', $valid), '100', 1],
    ];

    foreach ($cases as $label => [$xml, $threshold, $expected]) {
        file_put_contents($report, $xml);
        [$exit, $output] = $run([$coverageScript, $report, $threshold]);
        if ($exit !== $expected) {
            throw new RuntimeException(sprintf('Coverage case "%s" returned %d, expected %d: %s', $label, $exit, $expected, $output));
        }
        ++$checks;
    }

    [$exit, $output] = $run([$coverageScript, $root . '/missing.xml', '100']);
    if (1 !== $exit || ! str_contains($output, 'readable Cobertura report')) {
        throw new RuntimeException('Coverage must reject a missing report.');
    }
    ++$checks;
    [$exit, $output] = $run([$phpdocScript, $source]);

    if (0 !== $exit) {
        throw new RuntimeException('PHPDoc must accept an explanatory method summary: ' . $output);
    }

    ++$checks;

    foreach ([
        '<?php class Fixture { public function value(): int { return 1; } }',
        '<?php class Fixture { /** @return int */ public function value(): int { return 1; } }',
        '<?php class Fixture { /** {@inheritdoc} */ public function value(): int { return 1; } }',
        '<?php class Fixture { /** value */ public function value(): int { return 1; } }',
        '<?php class Broken {',
    ] as $php) {
        file_put_contents($source, $php);
        [$exit, $output] = $run([$phpdocScript, $source]);

        if (1 !== $exit) {
            throw new RuntimeException('PHPDoc must reject missing explanations or invalid source: ' . $output);
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
