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
    file_put_contents($source, '<?php class Fixture { /** Returns the fixture value without observable side effects. */ public function value(): int { return 1; } }');
    $name = htmlspecialchars($source, ENT_QUOTES | ENT_XML1);
    $valid = '<coverage><project><file name="' . $name . '"><class name="Fixture"><metrics statements="1" coveredstatements="1"/></class><line type="stmt" num="1" count="1"/><metrics statements="1" coveredstatements="1"/></file><metrics statements="1" coveredstatements="1"/></project></coverage>';
    $partial = str_replace(['coveredstatements="1"', 'count="1"'], ['coveredstatements="0"', 'count="0"'], $valid);
    $cases = [
        'complete coverage' => [$valid, '100', 0],
        'partial coverage' => [$partial, '100', 1],
        'lower explicit threshold' => [$partial, '0', 0],
        'malformed XML' => ['<coverage>', '100', 1],
        'wrong document shape' => ['<not-coverage/>', '100', 1],
        'missing metrics' => [str_replace('<metrics statements="1" coveredstatements="1"/>', '', $valid), '100', 1],
        'missing statements' => [str_replace(' statements="1"', '', $valid), '100', 1],
        'invalid metric value' => [str_replace('statements="1"', 'statements="invalid"', $valid), '100', 1],
        'zero denominator' => [str_replace(['statements="1"', 'count="1"'], ['statements="0"', 'count="0"'], $valid), '100', 1],
        'impossible numerator' => [str_replace('coveredstatements="1"', 'coveredstatements="2"', $valid), '100', 1],
        'inconsistent line hits' => [str_replace('count="1"', 'count="0"', $valid), '100', 1],
        'inconsistent project metrics' => [str_replace('</file><metrics statements="1"', '</file><metrics statements="2"', $valid), '100', 1],
        'invalid threshold' => [$valid, 'NaN', 1],
        'threshold above 100' => [$valid, '101', 1],
        'document type' => ['<!DOCTYPE coverage [<!ENTITY data "x">]>' . $valid, '100', 1],
        'unknown production file' => [str_replace($name, $name . '.unknown', $valid), '100', 1],
        'missing class metrics' => [str_replace('<class name="Fixture"><metrics statements="1" coveredstatements="1"/></class>', '<class name="Fixture"/>', $valid), '100', 1],
        'duplicate file' => [str_replace('</project>', '<file name="' . $name . '"><metrics statements="0" coveredstatements="0"/></file></project>', $valid), '100', 1],
    ];

    foreach ($cases as $label => [$xml, $threshold, $expected]) {
        file_put_contents($report, $xml);
        [$exit, $output] = $run([$coverageScript, $report, $threshold, $root . '/src']);

        if ($exit !== $expected) {
            throw new RuntimeException(sprintf('Coverage case "%s" returned %d, expected %d: %s', $label, $exit, $expected, $output));
        }

        ++$checks;
    }

    file_put_contents($root . '/src/Omitted.php', '<?php class Omitted {}');
    file_put_contents($report, $valid);
    [$exit, $output] = $run([$coverageScript, $report, '100', $root . '/src']);

    if (1 !== $exit || ! str_contains($output, 'Production file missing from coverage')) {
        throw new RuntimeException('Coverage must reject source files omitted from the report.');
    }

    ++$checks;
    [$exit, $output] = $run([$coverageScript, $root . '/missing.xml', '100', $root . '/src']);

    if (1 !== $exit || ! str_contains($output, 'readable Clover report')) {
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
    foreach ([$report, $source, $root . '/src/Omitted.php'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    rmdir($root . '/src');
    rmdir($root);
}
