<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Renderer;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\Renderer\ChangesetRenderer;
use FastForward\Changelog\Changeset\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangesetRenderer::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class ChangesetRendererTest extends TestCase
{
    #[Test]
    public function persistsAnExplicitLowerImpactAndEveryAvailableOptionalField(): void
    {
        $body = "    code();  \n\nSecond paragraph.  ";
        $fragment = new Changeset('entry.md', Category::Changed, 1, 2, 'coisa', $body, VersionImpact::Patch);

        self::assertSame("---\ncategory: changed\ntype: patch\nissue: 1\npull_request: 2\nauthor: \"coisa\"\n---\n\n{$body}\n", new ChangesetRenderer()->render($fragment));
    }

    #[Test]
    public function persistsTheMaterializedDefaultAndOmitsUnavailableMetadata(): void
    {
        $fragment = new Changeset('entry.md', Category::Deprecated, null, null, null, 'Body.');

        self::assertSame("---\ncategory: deprecated\ntype: minor\n---\n\nBody.\n", new ChangesetRenderer()->render($fragment));
    }
}
