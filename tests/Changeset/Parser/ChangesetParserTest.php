<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Changeset\Parser;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Factory\ChangesetFactoryInterface;
use FastForward\Changelog\Changeset\Factory\ChangesetParseResultFactoryInterface;
use FastForward\Changelog\Changeset\Parser\ChangesetParser;
use FastForward\Changelog\Changeset\VersionImpact;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(ChangesetParser::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(ChangesetParseResult::class)]
#[UsesClass(VersionImpact::class)]
final class ChangesetParserTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    #[TestWith(['added', Category::Added, VersionImpact::Minor])]
    #[TestWith(['changed', Category::Changed, VersionImpact::Minor])]
    #[TestWith(['deprecated', Category::Deprecated, VersionImpact::Minor])]
    #[TestWith(['removed', Category::Removed, VersionImpact::Major])]
    #[TestWith(['fixed', Category::Fixed, VersionImpact::Patch])]
    #[TestWith(['security', Category::Security, VersionImpact::Patch])]
    public function infersLegacyDefaultsAndAllowsMissingOptionalMetadata(string $value, Category $category, VersionImpact $impact): void
    {
        $expected = new Changeset('entry.md', $category, null, null, null, 'Body.', $impact);
        $actual = $this->parser($expected)->parse('entry.md', "---\ncategory: {$value}\n---\n\nBody.\n");

        self::assertSame($expected, $actual->changeset);
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

        self::assertSame($expected, $this->parser($expected)->parse('/repo/.changelog/entry.md', $contents)->changeset);
    }

    #[Test]
    #[TestWith(['version: patch', VersionImpact::Patch])]
    #[TestWith(["type: patch\nversion: patch", VersionImpact::Patch])]
    public function migratesUnambiguousLegacyMetadata(string $impactFields, VersionImpact $impact): void
    {
        $expected = new Changeset('entry.md', Category::Changed, null, 42, 'coisa', 'Body.', $impact);
        $contents = "---\r\ncategory: changed\r\n{$impactFields}\r\nissue: null\r\npull-request: 42\r\nauthor: \"@coisa\"\r\n---\r\n\r\nBody.\r\n";

        self::assertSame($expected, $this->parser($expected)->parse('C:\\repo\\.changelog\\entry.md', $contents)->changeset);
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
            $actual = $this->parser()->parse('entry.md', $contents);
            self::assertContains('Author must be a GitHub login or null.', $actual->errors);
        } else {
            $expected = new Changeset('entry.md', Category::Fixed, null, null, $author, 'Body.', VersionImpact::Patch);
            self::assertSame($expected, $this->parser($expected)->parse('entry.md', $contents)->changeset);
        }
    }

    #[Test]
    public function retainsIndentedCodeHardBreaksAndInteriorBlankLines(): void
    {
        $body = "    echo \"kept\";  \n\nnext line  \n<!-- retained -->";
        $expected = new Changeset('entry.md', Category::Fixed, null, null, null, $body, VersionImpact::Patch);
        $contents = "---\ncategory: fixed\ntype: patch\n---\n \t\n{$body}\n\t \n";

        self::assertSame($expected, $this->parser($expected)->parse('entry.md', $contents)->changeset);
    }

    #[Test]
    public function reportsFilenameAndDelimiterErrorsWithoutConstructingAFragment(): void
    {
        $result = $this->parser()->parse('Bad Name.md', 'body');

        self::assertNull($result->changeset);
        self::assertSame([
            'Filename must match ^[a-z0-9]+(?:-[a-z0-9]+)*\\.md$.',
            'Fragment must start with a frontmatter delimiter.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsMissingClosingDelimiter(): void
    {
        self::assertSame(['Fragment frontmatter must have a closing delimiter.'], $this->parser()->parse('entry.md', "---\ncategory: fixed")->errors);
    }

    #[Test]
    public function accumulatesIndependentSchemaDiagnosticsAndDoesNotDropUnknownKeys(): void
    {
        $contents = "---\ncategory: wrong\ncategory: fixed\nissue: +1\npull_request: 0\nauthor: user-\ntype: release\nunknown: 1\nnot-a-pair\n---\n<!-- invisible -->";
        $result = $this->parser()->parse('entry.md', $contents);

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
        $result = $this->parser()->parse('entry.md', "---\n---\n<!-- never closes");

        self::assertSame([
            'Missing required frontmatter key "category".',
            'Category must be one of added, changed, deprecated, removed, fixed, security.',
            'Fragment body must contain non-comment Markdown text.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsConflictingCanonicalAndLegacyImpactAndDuplicateAliases(): void
    {
        $result = $this->parser()->parse('entry.md', "---\ncategory: added\ntype: patch\nversion: major\npull_request: 1\npull-request: 1\n---\nBody.");

        self::assertSame([
            'Duplicate frontmatter key "pull_request".',
            'Type and legacy version must not declare conflicting impacts.',
        ], $result->errors);
    }

    #[Test]
    public function rejectsInvalidLegacyImpactAndOverflowReferences(): void
    {
        $result = $this->parser()->parse('entry.md', "---\ncategory: fixed\nversion: invalid\nissue: 9999999999999999999999999\n---\nBody.");

        self::assertSame(['Issue must be a positive integer or null.', 'Version must be one of major, minor, patch.'], $result->errors);
    }

    /** Builds the parser using only mocked factory collaborators. */
    private function parser(?Changeset $expected = null): ChangesetParser
    {
        $changesets = $this->prophesize(ChangesetFactoryInterface::class);
        $results = $this->prophesize(ChangesetParseResultFactoryInterface::class);
        $results->invalid(Argument::type('string'), Argument::type('array'))
            ->will(static fn(array $arguments): ChangesetParseResult => new ChangesetParseResult($arguments[0], null, $arguments[1]));

        if (null !== $expected) {
            $changesets->create($expected->id, $expected->category, $expected->issue, $expected->pullRequest, $expected->author, $expected->description, $expected->type)
                ->willReturn($expected)->shouldBeCalledOnce();
            $results->valid($expected)->willReturn(new ChangesetParseResult($expected->id, $expected, []))->shouldBeCalledOnce();
        }

        return new ChangesetParser($changesets->reveal(), $results->reveal());
    }
}
