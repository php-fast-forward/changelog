<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console;

use FastForward\Changelog\Console\Input\MutationInput;
use FastForward\Changelog\Console\Input\ReleaseInput;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;

trait PlanFixtureTrait
{
    private function settings(): ReleaseInput
    {
        $input = new ReleaseInput();
        $input->workingDirectory = '/consumer';
        return $input;
    }

    private function mutation(bool $dry = false, bool $check = false): MutationInput
    {
        $input = new MutationInput();
        $input->release = $this->settings();
        $input->dryRun = $dry;
        $input->check = $check;
        return $input;
    }

    private function options(): ReleaseOptions
    {
        return new ReleaseOptions('/consumer');
    }

    private function plan(string $mode = 'release'): ReleasePlan
    {
        return new ReleasePlan(
            $this->options(),
            'plan-id',
            str_repeat('a', 40),
            '1.0.0',
            'release' === $mode ? '1.1.0' : null,
            'release' === $mode ? 'minor' : null,
            'release' === $mode ? ['/consumer/.changelog/one.md' => str_repeat('b', 64)] : [],
            [],
            '/consumer/CHANGELOG.md',
            'original',
            'none' === $mode ? 'original' : 'changed',
            'Exact notes\n',
            '/consumer/.changelog/release-plan.json',
            null,
            '{}',
        );
    }
}
