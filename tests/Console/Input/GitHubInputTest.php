<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Console\Input;

use FastForward\Changelog\Console\Input\GitHubInput;
use FastForward\Changelog\Console\Input\ReleaseInput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(GitHubInput::class)]
#[UsesClass(ReleaseInput::class)]
final class GitHubInputTest extends TestCase
{
    /** Unspecified operation options and repository identities do not invent authority or override service defaults. */
    public function testDefaultsContainOnlySharedSettings(): void
    {
        self::assertSame(['working-directory' => '.', 'fragment-directory' => '.changelog',
            'changelog-file' => 'CHANGELOG.md', 'locale' => 'en', 'template' => 'keep-a-changelog',
            'base-ref' => 'HEAD', 'tag-prefix' => 'v', 'source' => 'auto'], new GitHubInput()->values());
    }

    /** Explicit empty strings, false text and JSON remain distinguishable from omitted flags. */
    public function testExplicitValuesRetainTheirExactPublicInputMeaning(): void
    {
        $input = new GitHubInput();
        $input->release->workingDirectory = '/consumer';
        $input->release->repository = 'owner/package';
        $input->release->locale = 'pt-BR';
        $input->pullRequest = '';
        $input->dryRun = 'false';
        $input->securityAlertNumbers = '[7, 9]';
        $input->includeDev = 'false';
        $input->includeActions = 'true';
        $values = $input->values();
        self::assertSame('/consumer', $values['working-directory']);
        self::assertSame('owner/package', $values['repository']);
        self::assertSame('pt-BR', $values['locale']);
        self::assertSame('', $values['pull-request']);
        self::assertSame('false', $values['dry-run']);
        self::assertSame('[7, 9]', $values['security-alert-numbers']);
        self::assertSame('false', $values['include-dev']);
        self::assertSame('true', $values['include-actions']);
        self::assertArrayNotHasKey('target-sha', $values);
    }
}
