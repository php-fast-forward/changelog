<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Integration;

use RuntimeException;

/** Records isolated test identity before PHPUnit can buffer child events until termination. */
trait RecordsIntegrationProgress
{
    /**
     * Appends the active test and dataset to the explicitly configured diagnostic file before fixture side effects.
     *
     * @throws RuntimeException When the configured identity log cannot be written.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $path = getenv('FF_INTEGRATION_PROGRESS_LOG');
        if (false !== $path && '' !== $path && false === file_put_contents(
            $path,
            static::class . '::' . $this->nameWithDataSet() . PHP_EOL,
            FILE_APPEND | LOCK_EX,
        )) {
            throw new RuntimeException('Cannot record isolated integration test identity.');
        }
    }
}
