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
 * Rejects missing or inconsistent Clover evidence and enforces every production file.
 *
 * An optional source root exists solely for disposable checks of this verifier.
 */
use SebastianBergmann\CodeCoverage\StaticAnalysis\FileAnalyser;
use SebastianBergmann\CodeCoverage\StaticAnalysis\ParsingSourceAnalyser;

require dirname(__DIR__) . '/vendor/autoload.php';

$reportPath = $argv[1] ?? '';
$minimumArgument = $argv[2] ?? '100';
$sourceRoot = realpath($argv[3] ?? dirname(__DIR__) . '/src');

try {
    if (! is_numeric($minimumArgument) || ! is_finite((float) $minimumArgument)
        || (float) $minimumArgument < 0 || (float) $minimumArgument > 100) {
        throw new RuntimeException('The required coverage MUST be a number between 0 and 100.');
    }

    if (false === $sourceRoot || ! is_dir($sourceRoot)) {
        throw new RuntimeException('The production source root is missing.');
    }

    if ('' === $reportPath || ! is_file($reportPath) || ! is_readable($reportPath)) {
        throw new RuntimeException('A readable Clover report is required.');
    }

    $xml = file_get_contents($reportPath);

    if (false === $xml || str_contains(strtoupper($xml), '<!DOCTYPE') || str_contains(strtoupper($xml), '<!ENTITY')) {
        throw new RuntimeException('The Clover report cannot contain document types or entities.');
    }

    $previousErrors = libxml_use_internal_errors(true);

    try {
        $report = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }

    if (false === $report || 'coverage' !== $report->getName() || 1 !== count($report->project)
        || 1 !== count($report->project->metrics)) {
        throw new RuntimeException('A Clover coverage/project element with one metrics element is required.');
    }

    /** @return array{0: int, 1: int} Validated executable and covered statement counts. */
    $readMetrics = static function (SimpleXMLElement $metrics): array {
        $values = [];

        foreach (['statements', 'coveredstatements'] as $attribute) {
            $value = (string) $metrics[$attribute];

            if (1 !== preg_match('/^(?:0|[1-9]\d*)$/D', $value)
                || strlen($value) > strlen((string) PHP_INT_MAX)
                || (strlen($value) === strlen((string) PHP_INT_MAX) && strcmp($value, (string) PHP_INT_MAX) > 0)) {
                throw new RuntimeException('Missing or invalid Clover ' . $attribute . ' metric.');
            }

            $values[] = (int) $value;
        }

        if ($values[1] > $values[0]) {
            throw new RuntimeException('Covered statements cannot exceed executable statements.');
        }

        return [$values[0], $values[1]];
    };

    [$totalStatements, $coveredStatements] = $readMetrics($report->project->metrics);

    if (0 === $totalStatements) {
        throw new RuntimeException('The Clover report contains no executable production statements.');
    }

    $files = $report->project->xpath('.//file') ?: [];
    $seen = [];
    $fileStatements = 0;
    $fileCovered = 0;
    $failures = [];
    $analyser = new FileAnalyser(new ParsingSourceAnalyser(), false, false);

    foreach ($files as $file) {
        $path = realpath((string) $file['name']);

        if (false === $path || ! str_starts_with($path, $sourceRoot . DIRECTORY_SEPARATOR) || isset($seen[$path])
            || 1 !== count($file->metrics)) {
            throw new RuntimeException('Every Clover file MUST identify a unique production file with metrics.');
        }

        $seen[$path] = true;
        [$statements, $covered] = $readMetrics($file->metrics);
        $fileStatements += $statements;
        $fileCovered += $covered;
        $lineEvidence = $file->line;
        $statementLines = 0;
        $coveredLines = 0;
        $analysis = $analyser->analyse($path);
        $executableSourceLines = $analysis->executableLines();
        $methodDeclarations = [];

        foreach ([...$analysis->classes(), ...$analysis->traits()] as $codeUnit) {
            foreach ($codeUnit->methods() as $method) {
                $methodDeclarations[$method->startLine()] = $method->name();
            }
        }
        $uncovered = [];
        $lineNumbers = [];

        foreach ($lineEvidence as $line) {
            $count = (string) $line['count'];
            $number = (string) $line['num'];

            if (1 !== preg_match('/^\d+$/D', $count) || 1 !== preg_match('/^[1-9]\d*$/D', $number)
                || isset($lineNumbers[$number])) {
                throw new RuntimeException('Clover statement lines require unique positive numbers and non-negative hit counts.');
            }

            $lineNumbers[$number] = true;

            $type = (string) $line['type'];

            if ('method' === $type) {
                if (($methodDeclarations[(int) $number] ?? null) !== (string) $line['name']) {
                    throw new RuntimeException('Clover method records MUST match a source method name and declaration line.');
                }

                // Clover can replace a statement on the declaration line with
                // a method record. Only a declaration the source analyser marks
                // executable can supply that statement; metadata-only method
                // declarations MUST NOT compensate for absent statement nodes.
                if (! isset($executableSourceLines[(int) $number])) {
                    continue;
                }
            } elseif ('stmt' !== $type || ! isset($executableSourceLines[(int) $number])) {
                throw new RuntimeException('Clover statement records MUST identify executable source lines.');
            }

            ++$statementLines;

            if ((int) $count > 0) {
                ++$coveredLines;
            } else {
                $uncovered[] = $path . ':' . $number;
            }
        }

        if ($statements !== $statementLines || $covered !== $coveredLines) {
            throw new RuntimeException('Clover file metrics do not match executable line evidence: ' . $path);
        }

        if ($statements > 0 && ($covered / $statements) * 100 < (float) $minimumArgument) {
            $failures[] = sprintf('%s %.2f%%: %s', $path, ($covered / $statements) * 100, implode(', ', $uncovered));
        }

        foreach ($file->class as $class) {
            if ('' === (string) $class['name'] || 1 !== count($class->metrics)) {
                throw new RuntimeException('Every covered class requires a name and statement metrics.');
            }

            [$classStatements, $classCovered] = $readMetrics($class->metrics);

            if ($classStatements > $statements || $classCovered > $covered) {
                throw new RuntimeException('Class statement metrics cannot exceed their file metrics.');
            }

            if ($classStatements > 0 && ($classCovered / $classStatements) * 100 < (float) $minimumArgument) {
                $failures[] = sprintf('%s %.2f%%', (string) $class['name'], ($classCovered / $classStatements) * 100);
            }
        }
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

    foreach ($iterator as $file) {
        if ($file->isFile() && 'php' === $file->getExtension() && ! isset($seen[$file->getRealPath()])) {
            throw new RuntimeException('Production file missing from coverage: ' . $file->getPathname());
        }
    }

    if ($fileStatements !== $totalStatements || $fileCovered !== $coveredStatements) {
        throw new RuntimeException('Project metrics do not match the complete production file evidence.');
    }

    $coverage = ($coveredStatements / $totalStatements) * 100;

    if ($coverage < (float) $minimumArgument || [] !== $failures) {
        throw new RuntimeException(sprintf("Line coverage %.2f%% is below the required %.2f%%.\n%s", $coverage, (float) $minimumArgument, implode("\n", $failures)));
    }

    fwrite(STDOUT, sprintf("Line coverage %.2f%% meets the required %.2f%% for every production file and class (%d statements).\n", $coverage, (float) $minimumArgument, $totalStatements));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Coverage verification failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
