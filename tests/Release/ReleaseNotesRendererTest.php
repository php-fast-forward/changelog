<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Release\ReleaseNotesRenderer;
use FastForward\Changelog\Template\TemplateInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleaseNotesRenderer::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
final class ReleaseNotesRendererTest extends TestCase
{
    /** Empty inventories MUST NOT produce an invented release note. */
    public function testEmptyInventoryProducesNoBody(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        self::assertSame('', new ReleaseNotesRenderer()->render([], $template));
    }

    /** Inventory discovery does not request headings for absent categories. */
    public function testEmptyInventoryDoesNotResolvePresentation(): void
    {
        $template = $this->createMock(TemplateInterface::class);
        $template->expects(self::never())->method('categoryHeading');
        self::assertSame('', new ReleaseNotesRenderer()->render([], $template));
    }

    /** Descriptions retain Markdown, stable category order and useful references without technical metadata. */
    public function testCategoriesAndIdsHaveStableOrderAndTrustedReferences(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => '### custom ' . $category);
        $changes = [
            new Changeset('z.md', Category::Fixed, null, null, null, 'Fix z.'),
            new Changeset('b.md', Category::Added, 12, 42, 'someone[bot]', "Feature **b**.\n\n```php\nreturn true;\n```", VersionImpact::Patch),
            new Changeset('a.md', Category::Added, null, null, null, 'Feature a.'),
        ];
        $notes = new ReleaseNotesRenderer()->render($changes, $template, 'owner/project');
        self::assertStringNotContainsString('fast-forward-changelog:', $notes);
        self::assertStringContainsString('### custom added', $notes);
        self::assertLessThan(strpos($notes, 'Feature **b**.'), strpos($notes, 'Feature a.'));
        self::assertLessThan(strpos($notes, '### custom fixed'), strpos($notes, '### custom added'));
        self::assertStringNotContainsString('b.md', $notes);
        self::assertStringContainsString("-\n  Feature **b**.\n  \n  ```php\n  return true;\n  ```\n\n  ([#42](https://github.com/owner/project/pull/42), [#12](https://github.com/owner/project/issues/12), @someone[bot])", $notes);
        self::assertStringEndsWith("\n", $notes);
    }

    /** Unknown repository context preserves references without fabricating destinations. */
    public function testPlainReferencesAndSingleLineDescriptionsAreRetained(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Changed');
        $notes = new ReleaseNotesRenderer()->render([new Changeset('one.md', Category::Changed, 7, 8, null, 'Keep two spaces.  ')], $template);
        self::assertStringContainsString('- Keep two spaces.   (#8, #7)', $notes);
    }

    /** Standalone fenced descriptions remain fenced after list indentation. */
    public function testFencedBodyAndNormalAuthorAreWellFormed(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Fixed');
        $notes = new ReleaseNotesRenderer()->render([new Changeset('code.md', Category::Fixed, null, null, 'human', "```php\nreturn 1;\n```")], $template);
        self::assertStringContainsString("-\n  ```php\n  return 1;\n  ```\n\n  ([@human](https://github.com/human))", $notes);
    }
}
