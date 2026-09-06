<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use function Safe\simplexml_load_file;

$reportPath = $argv[1] ?? '';
$minimumCoverage = (float) ($argv[2] ?? 100);
$report = simplexml_load_file($reportPath);
$metrics = $report->project->metrics;
$totalStatements = (int) $metrics['statements'];
$coveredStatements = (int) $metrics['coveredstatements'];
$coverage = 0 === $totalStatements ? 100.0 : ($coveredStatements / $totalStatements) * 100;

if ($coverage < $minimumCoverage) {
    $uncoveredLines = [];

    foreach ($report->xpath('//line[@type="stmt" and @count="0"]') as $line) {
        $files = $line->xpath('ancestor::file');
        $uncoveredLines[] = \sprintf('%s:%d', (string) $files[0]['name'], (int) $line['num']);
    }

    fwrite(
        STDERR,
        sprintf(
            "Line coverage %.2f%% is below the required %.2f%%. Uncovered: %s\n",
            $coverage,
            $minimumCoverage,
            implode(', ', $uncoveredLines),
        ),
    );

    exit(1);
}

fwrite(STDOUT, sprintf("Line coverage %.2f%% meets the required %.2f%%.\n", $coverage, $minimumCoverage));
