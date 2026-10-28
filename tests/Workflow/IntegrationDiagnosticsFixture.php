<?php

declare(strict_types=1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/** Verifies real PHPUnit diagnostics survive a failing test followed by an interrupted run. */
final class IntegrationDiagnosticsFixture
{
    /** Uses the workflow's actual arguments against disposable tests; no network or repository state is involved. */
    public static function verify(string $root, array $job): int
    {
        $step = workflowStep($job, 'Test Integration Contracts In Disposable Git Repositories');
        $prepare = workflowStep($job, 'Prepare Integration Diagnostics Directory');
        $upload = workflowStep($job, 'Preserve Windows Integration Diagnostics');
        verifyWorkflow(
            str_contains($step['run'], '--testsuite integration --no-coverage')
                && str_contains($step['run'], '--log-junit .build/integration.xml')
                && str_contains($step['run'], '--log-events-text .build/integration-events.log --debug')
                && ! ($step['continue-on-error'] ?? false),
            'Every platform must run all integration contracts with live diagnostics and retain failures.',
        );
        verifyWorkflow(
            str_contains($upload['if'], 'always()')
                && str_contains($upload['if'], "matrix.os == 'windows-latest'")
                && ['.build/integration.xml', '.build/integration-events.log'] === preg_split(
                    '/\s+/',
                    trim($upload['with']['path']),
                ),
            'Windows must preserve both final timings and incremental events after failure or interruption.',
        );
        $directory = sys_get_temp_dir() . '/changelog-integration-diagnostics-' . bin2hex(random_bytes(8));
        $filesystem = new Filesystem();
        $filesystem->mkdir($directory, 0700);
        try {
            $setup = new Process(['bash', '-c', $prepare['run']], $directory, [
                'GITHUB_TOKEN' => false, 'GH_TOKEN' => false, 'BASH_ENV' => false,
            ]);
            $setup->setTimeout(10);
            verifyWorkflow(0 === $setup->run() && is_dir($directory . '/.build'),
                'The actual workflow must prepare the diagnostics directory on a clean checkout.');
            file_put_contents($directory . '/DiagnosticsTest.php', <<<'PHP_WRAP'
            <?php
            use PHPUnit\Framework\TestCase;

            final class DiagnosticsTest extends TestCase
            {
                /** A real failure must remain observable before interruption. */
                public function testFailure(): void
                {
                    self::fail('Synthetic integration failure before interruption.');
                }

                /** Abrupt termination prevents PHPUnit from completing its final report. */
                public function testInterrupted(): void
                {
                    exit(23);
                }
            }
            PHP_WRAP);
            $arguments = preg_split(
                '/\s+/',
                trim(str_replace('${{ matrix.integration-arguments }}', '', $step['run'])),
            );
            // Keep the diagnostic flags verbatim; only select the disposable fixture instead of the real suite.
            $arguments = array_values(array_filter(
                $arguments,
                static fn(string $argument): bool => ! in_array($argument, [
                    'vendor/bin/phpunit', '--testsuite', 'integration',
                ], true),
            ));
            $process = new Process([
                PHP_BINARY, $root . '/vendor/bin/phpunit', '--no-configuration',
                ...$arguments, $directory . '/DiagnosticsTest.php',
            ], $directory, ['XDEBUG_MODE' => 'off', 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false]);
            $process->setTimeout(30);
            $status = $process->run();
            verifyWorkflow(0 !== $status, 'Interrupted integration must retain a nonzero exit status: '
                . $process->getOutput() . $process->getErrorOutput());
            verifyWorkflow(is_file($directory . '/.build/integration-events.log'),
                'The incremental event log must exist before PHPUnit finalizes: ' . $process->getOutput());
            $events = file_get_contents($directory . '/.build/integration-events.log');
            verifyWorkflow(
                str_contains($events, 'Test Failed (DiagnosticsTest::testFailure)')
                    && str_contains($events, 'Synthetic integration failure before interruption.')
                    && str_contains($events, 'Test Prepared (DiagnosticsTest::testInterrupted)')
                    && ! str_contains($events, 'Test Finished (DiagnosticsTest::testInterrupted)'),
                'Incremental events must identify the prior failure and the last unfinished test.',
            );
            verifyWorkflow(
                str_contains($process->getOutput(), 'Synthetic integration failure before interruption.')
                    && str_contains($process->getOutput(), 'DiagnosticsTest::testInterrupted'),
                'Live job output must expose failure details and the interrupted test without a final JUnit report.',
            );

            return 7;
        } finally {
            $filesystem->remove($directory);
        }
    }
}
