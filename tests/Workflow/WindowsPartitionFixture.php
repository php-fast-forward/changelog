<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** Proves Windows partitions cover every integration test once and their required aggregate rejects incomplete execution. */
final class WindowsPartitionFixture
{
    /** Discovers real PHPUnit test IDs using the actual matrix selectors, without executing integration side effects. */
    public static function verify(string $root, array $jobs): int
    {
        $matrix = $jobs['tests']['strategy']['matrix'];
        $windows = array_values(array_filter($matrix['include'],
            static fn(array $row): bool => 'windows-latest' === $row['os']));
        verifyWorkflow(
            false === $jobs['tests']['strategy']['fail-fast']
                && ! ($jobs['tests']['continue-on-error'] ?? false)
                && ['references', 'stacked-references', 'provenance', 'publication'] === array_column(
                    $windows,
                    'shard',
                ),
            'Four independent Windows jobs must finish without hiding failed siblings.',
        );
        foreach ($matrix['include'] as $row) {
            verifyWorkflow('windows-latest' === $row['os'] || '' === $row['integration-arguments'],
                'Linux and ARM must continue running the complete integration suite.');
        }
        $all = self::tests($root, '');
        $partitioned = [];
        foreach ($windows as $row) {
            $selected = self::tests($root, $row['integration-arguments']);
            verifyWorkflow([] !== $selected, 'Every Windows partition must select real integration tests.');
            $partitioned = [...$partitioned, ...$selected];
        }
        sort($partitioned);
        verifyWorkflow($all === $partitioned,
            'Windows partitions must execute the complete integration test inventory exactly once, without gaps or overlap: '
            . json_encode(['missing' => array_values(array_diff($all, $partitioned)),
                'extra' => array_values(array_diff($partitioned, $all)),
                'duplicates' => array_keys(
                    array_filter(array_count_values($partitioned), static fn(int $count): bool => 1 < $count),
                )], JSON_THROW_ON_ERROR));
        $upload = workflowStep($jobs['tests'], 'Preserve Windows Integration Diagnostics');
        verifyWorkflow('windows-integration-${{ matrix.shard }}' === $upload['with']['name'],
            'Concurrent Windows partitions must preserve separate diagnostic artifacts.');
        $gate = $jobs['windows'];
        $step = workflowStep($gate, 'Require All Platform And Windows Shard Results');
        verifyWorkflow(
            'tests' === $gate['needs'] && '${{ always() && !cancelled() }}' === $gate['if']
                && '${{ needs.tests.result }}' === $step['env']['FF_RESULT']
                && ! ($gate['continue-on-error'] ?? false),
            'The existing required Windows context must wait for the entire platform matrix and retain failures.',
        );
        foreach (['success' => 0, 'failure' => 1, 'cancelled' => 1, 'skipped' => 1, '' => 1] as $result => $expected) {
            $process = new Process(['bash', '-c', $step['run']], $root, [
                'FF_RESULT' => $result, 'BASH_ENV' => false, 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false,
            ]);
            $process->setTimeout(10);
            verifyWorkflow($expected === $process->run(),
                'The required Windows aggregate must reject a failed, cancelled, skipped or missing matrix result.');
        }

        return 19;
    }

    /** Lists each data-provider case so partition coverage cannot be inferred from class names or test counts alone. */
    private static function tests(string $root, string $arguments): array
    {
        $process = new Process([
            PHP_BINARY, $root . '/vendor/bin/phpunit', '--testsuite', 'integration', '--no-coverage', '--list-tests',
            ...('' === $arguments ? [] : preg_split('/\s+/', $arguments)),
        ], $root, ['XDEBUG_MODE' => 'off', 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false]);
        $process->setTimeout(30);
        verifyWorkflow(0 === $process->run(), 'PHPUnit must accept the actual Windows partition selector.');
        preg_match_all('/^ - (.+)$/m', $process->getOutput(), $matches);
        $tests = array_map(trim(...), $matches[1]);
        verifyWorkflow([] !== $tests, 'PHPUnit must provide an integration test inventory.');
        sort($tests);

        return $tests;
    }
}
