<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Parser;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Parser\ChangesetParser;
use FastForward\Changelog\Markdown\BlockStructure;
use FastForward\Changelog\Version\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[UsesClass(BlockStructure::class)]
#[CoversClass(ChangesetParser::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(ChangesetParseResult::class)]
#[UsesClass(VersionImpact::class)]
final class ChangesetParserTest extends TestCase
{
    #[Test]
    #[TestWith(['added', Category::Added, VersionImpact::Minor])]
    #[TestWith(['changed', Category::Changed, VersionImpact::Minor])]
    #[TestWith(['deprecated', Category::Deprecated, VersionImpact::Minor])]
    #[TestWith(['removed', Category::Removed, VersionImpact::Major])]
    #[TestWith(['fixed', Category::Fixed, VersionImpact::Patch])]
    #[TestWith(['security', Category::Security, VersionImpact::Patch])]
    public function infersLegacyDefaultsAndAllowsMissingOptionalMetadata(
        string $value,
        Category $category,
        VersionImpact $impact,
    ): void {
        $expected = new Changeset('entry.md', $category, null, null, null, 'Body.', $impact);
        $actual = new ChangesetParser()->parse('entry.md', "---\ncategory: {$value}\n---\n\nBody.\n");

        self::assertEquals($expected, $actual->changeset);
        self::assertSame([], $actual->errors);
    }

    #[Test]
    #[TestWith(['patch', VersionImpact::Patch])]
    #[TestWith(['minor', VersionImpact::Minor])]
    #[TestWith(['major', VersionImpact::Major])]
    public function explicitTypeOverridesRemovedCategoryIncludingLowerImpact(string $value, VersionImpact $impact): void
    {
        $expected = new Changeset('entry.md', Category::Removed, 12, 34, 'dependabot[bot]', 'Body.', $impact);
        $contents = "---\ncategory: removed\ntype: {$value}\nissue: 12\npull_request: 34\nauthor: dependabot[bot]\n---\nBody.";

        self::assertEquals($expected, new ChangesetParser()->parse('/repo/.changelog/entry.md', $contents)->changeset);
    }

    #[Test]
    #[TestWith(['version: patch', VersionImpact::Patch])]
    #[TestWith(["type: patch\nversion: patch", VersionImpact::Patch])]
    public function migratesUnambiguousLegacyMetadata(string $impactFields, VersionImpact $impact): void
    {
        $expected = new Changeset('entry.md', Category::Changed, null, 42, 'coisa', 'Body.', $impact);
        $contents = "---\r\ncategory: changed\r\n{$impactFields}\r\nissue: null\r\npull-request: 42\r\nauthor: \"@coisa\"\r\n---\r\n\r\nBody.\r\n";

        self::assertEquals(
            $expected,
            new ChangesetParser()->parse('C:\\repo\\.changelog\\entry.md', $contents)->changeset,
        );
    }

    #[Test]
    #[TestWith(['author: null', null])]
    #[TestWith(['author: ""', ''])]
    #[TestWith(["author: 'github-actions[bot]'", 'github-actions[bot]'])]
    #[TestWith(['author: "coisa"', 'coisa'])]
    public function interpretsSimpleQuotedAndNullableAuthors(string $field, ?string $author): void
    {
        $contents = "---\ncategory: fixed\ntype: patch\n{$field}\n---\nBody.";

        if ('' === $author) {
            $actual = new ChangesetParser()->parse('entry.md', $contents);
            self::assertContains('Author must be a GitHub login or null.', $actual->errors);
        } else {
            $expected = new Changeset('entry.md', Category::Fixed, null, null, $author, 'Body.', VersionImpact::Patch);
            self::assertEquals($expected, new ChangesetParser()->parse('entry.md', $contents)->changeset);
        }
    }

    #[Test]
    public function retainsIndentedCodeHardBreaksAndInteriorBlankLines(): void
    {
        $body = "    echo \"kept\";  \n\nnext line  \n<!-- retained -->";
        $expected = new Changeset('entry.md', Category::Fixed, null, null, null, $body, VersionImpact::Patch);
        $contents = "---\ncategory: fixed\ntype: patch\n---\n \t\n{$body}\n\t \n";

        self::assertEquals($expected, new ChangesetParser()->parse('entry.md', $contents)->changeset);
    }

    /** Accepted fragments must serialize into the immutable JSON plan instead of failing only during release. */
    #[Test]
    #[TestWith(['ff'])]
    #[TestWith(['c0af'])]
    #[TestWith(['eda080'])]
    public function rejectsInvalidUtf8BeforeConstructingAChangeset(string $hex): void
    {
        $result = new ChangesetParser()->parse('entry.md', "---\ncategory: fixed\n---\n\nBody " . hex2bin($hex));

        self::assertFalse($result->isValid());
        self::assertContains(
            'Fragment contents must be valid UTF-8 for release plans and automation output.',
            $result->errors,
        );
    }

    /** Each rendered fragment must close its own fence without depending on another fragment's bytes. */
    #[Test]
    #[TestWith(["```php\necho 'example';"])]
    #[TestWith(["~~~text\nexample\n```"])]
    #[TestWith(["````php\nexample\n```"])]
    #[TestWith(["  ```php\nUnclosed real list-contained fence."])]
    #[TestWith(["- ```php\n  example"])]
    #[TestWith(["1. ~~~php\n   example\n   ```"])]
    #[TestWith(['```php'])]
    #[TestWith([' ```php'])]
    #[TestWith(['  ```php'])]
    #[TestWith(['- ```php'])]
    #[TestWith(["- ```text\n  example\n- Next item."])]
    #[TestWith(["- Visible <!-- hidden\n  ~~~\n  --> Visible."])]
    #[TestWith(["- Use `some\n  ~~~\n  code` safely."])]
    public function rejectsFencesThatWouldPreventReleaseHistoryRendering(string $body): void
    {
        $result = new ChangesetParser()->parse('entry.md', "---\ncategory: fixed\n---\n\n" . $body);

        self::assertFalse($result->isValid());
        self::assertContains(
            'Fragment code fences must close before the end of their rendered release entry.',
            $result->errors,
        );
    }

    /** Valid Markdown whitespace, Unicode, paired fences and nested/inline examples remain byte-identical. */
    #[Test]
    #[TestWith(["```php\necho 'exemplo';  \n```"])]
    #[TestWith(["~~~text\n```\n~~~"])]
    #[TestWith(["````php\n```\n````"])]
    #[TestWith([" ```php\n example\n ```"])]
    #[TestWith(["    ```php\nIndented example remains data."])]
    #[TestWith([" ```php\nexample\n  ```"])]
    #[TestWith(["```invalid`info\nTexto preservado: ação."])]
    #[TestWith(["```php\nexample\n````  "])]
    #[TestWith(["- ```php\n  example\n  ```"])]
    #[TestWith(["1. ~~~php\n   example\n   ~~~"])]
    #[TestWith(["- Parent.\n\n  ```md\n  - Example.\n  ```"])]
    #[TestWith(["  - ```php\n    example\n      ```"])]
    #[TestWith(["- - ```php\n    code();\n    ```"])]
    #[TestWith(["Description.\n\n    <!-- literal HTML example"])]
    #[TestWith(["- Description.\n\n      <!-- literal HTML example"])]
    #[TestWith(["> ~~~html\n> <!-- literal\n> ~~~"])]
    #[TestWith(["> ~~~html\n>example\n>~~~"])]
    #[TestWith(["- Parent.\n  - Child.\n\n    > ~~~html\n    > <!-- literal\n    > ~~~"])]
    #[TestWith(["- See [guide].\n\n[guide]: https://example.test"])]
    #[TestWith(["- <!-- hidden\n  ~~~\n  --> Visible improvement."])]
    #[TestWith(["- <!-- hidden\n  ```\n  --> Visible improvement."])]
    #[TestWith(["- Visible <!-- literal\n  ~~~\n  --> Code example.\n  ~~~"])]
    #[TestWith(["- Use `literal\n  ~~~\n  code` example.\n  ~~~"])]
    public function retainsReleaseCompatibleMarkdownExactly(string $body): void
    {
        $expected = new Changeset('entry.md', Category::Fixed, null, null, null, $body, VersionImpact::Patch);
        $result = new ChangesetParser()->parse('entry.md', "---\ncategory: fixed\n---\n\n" . $body . "\n");

        self::assertEquals($expected, $result->changeset);
        self::assertSame($body, $result->changeset->description);
    }

    /** A visible paragraph cannot make an unclosed HTML comment safe for later reference paragraphs. */
    public function testRejectsUnclosedCommentAfterVisibleProse(): void
    {
        $result = new ChangesetParser()->parse(
            'entry.md',
            "---\ncategory: fixed\n---\n\nVisible prose.\n\n<!-- not closed",
        );

        self::assertContains(
            'Fragment HTML comments must close before the end of their rendered release entry.',
            $result->errors,
        );
    }

    #[Test]
    public function reportsFilenameAndDelimiterErrorsWithoutConstructingAFragment(): void
    {
        $result = new ChangesetParser()->parse('Bad Name.md', 'body');

        self::assertNull($result->changeset);
        self::assertSame([
            'Filename must match ^[a-z0-9]+(?:-[a-z0-9]+)*\\.md$.',
            'Fragment must start with a frontmatter delimiter.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsMissingClosingDelimiter(): void
    {
        self::assertSame([
            'Fragment frontmatter must have a closing delimiter.'],
            new ChangesetParser()->parse('entry.md', "---\ncategory: fixed")->errors,
        );
    }

    #[Test]
    public function accumulatesIndependentSchemaDiagnosticsAndDoesNotDropUnknownKeys(): void
    {
        $contents = "---\ncategory: wrong\ncategory: fixed\nissue: +1\npull_request: 0\nauthor: user-\ntype: release\nunknown: 1\nnot-a-pair\n---\n<!-- invisible -->";
        $result = new ChangesetParser()->parse('entry.md', $contents);

        self::assertSame([
            'Duplicate frontmatter key "category".',
            'Unknown frontmatter key "unknown".',
            'Invalid frontmatter line "not-a-pair".',
            'Category must be one of added, changed, deprecated, removed, fixed, security.',
            'Issue must be a positive integer or null.',
            'Pull request must be a positive integer or null.',
            'Author must be a GitHub login or null.',
            'Type must be one of major, minor, patch.',
            'Fragment body must contain non-comment Markdown text.',
        ], $result->errors);
    }

    #[Test]
    public function requiresACategoryAndRejectsOpenCommentOnlyBody(): void
    {
        $result = new ChangesetParser()->parse('entry.md', "---\n---\n<!-- never closes");

        self::assertSame([
            'Missing required frontmatter key "category".',
            'Category must be one of added, changed, deprecated, removed, fixed, security.',
            'Fragment body must contain non-comment Markdown text.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsConflictingCanonicalAndLegacyImpactAndDuplicateAliases(): void
    {
        $result = new ChangesetParser()->parse(
            'entry.md',
            "---\ncategory: added\ntype: patch\nversion: major\npull_request: 1\npull-request: 1\n---\nBody.",
        );

        self::assertSame([
            'Duplicate frontmatter key "pull_request".',
            'Type and legacy version must not declare conflicting impacts.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsInvalidLegacyImpactAndOverflowReferences(): void
    {
        $result = new ChangesetParser()->parse(
            'entry.md',
            "---\ncategory: fixed\nversion: invalid\nissue: 9999999999999999999999999\n---\nBody.",
        );

        self::assertSame([
            'Issue must be a positive integer or null.', 'Version must be one of major, minor, patch.'],
            $result->errors,
        );
    }

}
