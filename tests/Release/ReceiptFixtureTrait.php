<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

/** Provides deterministic value fixtures without any external I/O or entropy. */
trait ReceiptFixtureTrait
{
    /** Supplies every schema-one field with exact byte hashes. */
    private static function evidence(): array
    {
        return [
            'schema' => 1, 'base_sha' => str_repeat('a', 40), 'current_version' => '1.0.0',
            'next_version' => '1.0.1', 'impact' => 'patch',
            'consumed' => ['.changelog/a.md' => hash('sha256', 'alpha'), '.changelog/b.md' => hash('sha256', 'beta')],
            'historical_versions' => ['0.1.0'], 'changelog_file' => 'CHANGELOG.md', 'fragment_directory' => '.changelog',
            'locale' => 'en', 'template' => 'keep-a-changelog', 'tag_prefix' => 'v', 'repository' => null,
            'before_changelog_sha256' => hash('sha256', 'before'), 'changelog_contents' => 'after', 'after_changelog_sha256' => hash('sha256', 'after'),
            'notes' => "Exact  notes\n", 'notes_sha256' => hash('sha256', "Exact  notes\n"),
        ];
    }
}
