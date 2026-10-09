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

/** Checks native Cobertura totals and per-class coverage from a freshly generated unit report. */
$reportPath = $argv[1] ?? '';
$minimum = $argv[2] ?? '100';

try {
    if (! is_numeric($minimum) || ! is_finite((float) $minimum) || (float) $minimum < 0 || (float) $minimum > 100) {
        throw new RuntimeException('Required coverage must be a number between 0 and 100.');
    }
    if ('' === $reportPath || ! is_file($reportPath) || ! is_readable($reportPath)) {
        throw new RuntimeException('A readable Cobertura report is required.');
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $report = simplexml_load_file($reportPath, SimpleXMLElement::class, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (false === $report || 'coverage' !== $report->getName()) {
        throw new RuntimeException('A valid Cobertura coverage document is required.');
    }
    $valid = filter_var((string) $report['lines-valid'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $covered = filter_var((string) $report['lines-covered'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if (false === $valid || false === $covered || $covered > $valid) {
        throw new RuntimeException('Cobertura line counts must have a positive denominator and a possible numerator.');
    }
    $coverage = ($covered / $valid) * 100;
    if ($coverage < (float) $minimum) {
        throw new RuntimeException(sprintf(
            'Line coverage %.2f%% is below required %.2f%%.',
            $coverage,
            (float) $minimum,
        ));
    }
    foreach ($report->xpath('/coverage/packages/package/classes/class') ?: [] as $class) {
        $lines = $class->lines->line;
        if (0 === count($lines)) {
            continue;
        }
        $rate = (string) $class['line-rate'];
        if (! is_numeric($rate) || ! is_finite((float) $rate) || (float) $rate < 0 || (float) $rate > 1
            || (float) $rate * 100 < (float) $minimum) {
            throw new RuntimeException('Class coverage is below the requirement: ' . (string) $class['name']);
        }
        foreach ($lines as $line) {
            $hits = filter_var((string) $line['hits'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if (false === $hits || (100.0 === (float) $minimum && 0 === $hits)) {
                throw new RuntimeException(
                    'Invalid or uncovered class line: ' . (string) $class['name'] . ':' . (string) $line['number'],
                );
            }
        }
    }
    fwrite(
        STDOUT,
        sprintf(
            "Line coverage %.2f%% meets required %.2f%% (%d/%d lines); every executable class meets the requirement.\n",
            $coverage,
            (float) $minimum,
            $covered,
            $valid,
        ),
    );
} catch (Throwable $exception) {
    fwrite(STDERR, 'Coverage verification failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
