<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Renderer;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Release\Renderer\ReleaseNotesRenderer;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestWith;
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
        $template->method('categoryHeading')->willReturnCallback(
            static fn(string $category): string => '### custom ' . $category,
        );
        $changes = [
            new Changeset('z.md', Category::Fixed, null, null, null, 'Fix z.'),
            new Changeset(
                'b.md',
                Category::Added,
                12,
                42,
                'someone[bot]',
                "Feature **b**.\n\n```php\nreturn true;\n```",
                VersionImpact::Patch,
            ),
            new Changeset('a.md', Category::Added, null, null, null, 'Feature a.'),
        ];
        $notes = new ReleaseNotesRenderer()->render($changes, $template, 'owner/project');
        self::assertStringNotContainsString('fast-forward-changelog:', $notes);
        self::assertStringContainsString('### custom added', $notes);
        self::assertLessThan(strpos($notes, 'Feature **b**.'), strpos($notes, 'Feature a.'));
        self::assertLessThan(strpos($notes, '### custom fixed'), strpos($notes, '### custom added'));
        self::assertStringNotContainsString('b.md', $notes);
        self::assertStringContainsString(
            "- Feature **b**.\n\n  ```php\n  return true;\n  ```\n\n  ([#42](https://github.com/owner/project/pull/42), [#12](https://github.com/owner/project/issues/12), @someone[bot])",
            $notes,
        );
        self::assertStringEndsWith("\n", $notes);
    }

    /** Unknown repository context preserves references without fabricating destinations. */
    public function testPlainReferencesAndSingleLineDescriptionsAreRetained(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Changed');
        $notes = new ReleaseNotesRenderer()->render(
            [new Changeset('one.md', Category::Changed, 7, 8, null, 'Keep two spaces.  ')],
            $template,
        );
        self::assertStringContainsString('- Keep two spaces.   (#8, #7)', $notes);
    }

    /** Simple changes form a compact list while categories retain a blank-line boundary. */
    public function testSimpleEntriesHaveNoBlankLinesBetweenChanges(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturnCallback(
            static fn(string $category): string => '### ' . $category,
        );
        $changes = [
            new Changeset('b.md', Category::Added, null, null, null, 'Second.'),
            new Changeset('a.md', Category::Added, null, null, null, 'First.'),
            new Changeset('c.md', Category::Fixed, null, null, null, 'Correction.'),
        ];
        self::assertSame(
            "### added\n\n- First.\n- Second.\n\n### fixed\n\n- Correction.\n",
            new ReleaseNotesRenderer()->render($changes, $template),
        );
    }

    /** Internal author paragraphs remain within their item without spreading blank separators to adjacent items. */
    public function testMultilineParagraphsRemainIndentedBetweenCompactEntries(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Fixed');
        $changes = [
            new Changeset('a.md', Category::Fixed, null, null, null, 'Before.'),
            new Changeset('b.md', Category::Fixed, null, null, null, "First paragraph.\n\nSecond paragraph."),
            new Changeset('c.md', Category::Fixed, null, null, null, 'After.'),
        ];
        self::assertSame(
            "### Fixed\n\n- Before.\n- First paragraph.\n\n  Second paragraph.\n- After.\n",
            new ReleaseNotesRenderer()->render($changes, $template),
        );
    }

    /** Standalone fenced descriptions remain fenced after list indentation. */
    public function testFencedBodyAndNormalAuthorAreWellFormed(): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Fixed');
        $notes = new ReleaseNotesRenderer()->render(
            [new Changeset('code.md', Category::Fixed, null, null, 'human', "```php\nreturn 1;\n```")],
            $template,
        );
        self::assertStringContainsString(
            "-\n  ```php\n  return 1;\n  ```\n\n  ([@human](https://github.com/human))",
            $notes,
        );
    }

    /** Soft-wrapped Composer notes must display text on the bullet's first line. */
    public function testComposerNoteHasOneVisibleBulletAndPreservesItsCompleteDescription(): void
    {
        $description = <<<'MARKDOWN'
        Atualiza as dependências Composer às versões compatíveis revisadas,
        mantendo o comportamento público da biblioteca. Preserva `prefer-stable: true` e
        as restrições de versão declaradas no pacote, com testes para instalações
        nas versões mínimas e máximas suportadas.
        MARKDOWN;
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Changed');
        $notes = new ReleaseNotesRenderer()->render(
            [new Changeset('composer-dependencies.md', Category::Changed, null, null, null, $description)],
            $template,
        );

        self::assertStringStartsWith("### Changed\n\n- Atualiza as dependências Composer", $notes);
        self::assertSame(1, preg_match_all('/^- /m', $notes));
        self::assertDoesNotMatchRegularExpression('/^-$/m', $notes);
        $item = substr($notes, strlen("### Changed\n\n"), -1);
        self::assertSame($description, str_replace("\n  ", "\n", substr($item, 2)));
    }

    /** Removing only the outer list wrapper must recover authored Markdown, including meaningful spaces. */
    #[TestWith(["First line.\nSoft continuation.", '- First line.'])]
    #[TestWith(["Hard break.  \nContinuation.", '- Hard break.  '])]
    #[TestWith(["First paragraph.\n\nSecond paragraph.", '- First paragraph.'])]
    #[TestWith(["Description.\n\n```php\n    return true;  \n\n```\n\nAfter code.", '- Description.'])]
    #[TestWith(["```php\nreturn true;\n```", "-\n  ```php"])]
    #[TestWith(["~~~php\nreturn true;\n~~~", "-\n  ~~~php"])]
    #[TestWith(["```literal``` span\nContinued text.", '- ```literal``` span'])]
    #[TestWith(["- Parent item\n  - Nested child\n\nAfter the nested list.", '- - Parent item'])]
    #[TestWith(["> Quoted text.\n>\n> Another paragraph.", '- > Quoted text.'])]
    #[TestWith(["    indented code;\n      keep extra indentation;", '-     indented code;'])]
    #[TestWith(["### Inner heading\n\nBody under the heading.", '- ### Inner heading'])]
    public function testAuthoredMarkdownSurvivesTheReadableListWrapper(string $description, string $prefix): void
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('categoryHeading')->willReturn('### Fixed');
        $notes = new ReleaseNotesRenderer()->render(
            [new Changeset('rich.md', Category::Fixed, null, null, null, $description)],
            $template,
        );
        $item = substr($notes, strlen("### Fixed\n\n"), -1);

        self::assertStringStartsWith($prefix, $item);
        self::assertDoesNotMatchRegularExpression('/^[ \t]+$/m', $notes);
        $unwrapped = preg_replace('/\A(?:-\n {2}|- )/', '', $item);
        self::assertSame($description, str_replace("\n  ", "\n", $unwrapped));
    }
}
