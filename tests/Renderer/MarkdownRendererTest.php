<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Renderer;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Entry\ChangelogEntryTypesInterface;
use FastForward\Changelog\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(MarkdownRenderer::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class MarkdownRendererTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function renderBuildsTheHeaderAndAnEmptyUnreleasedSectionWithoutReferences(): void
    {
        $document = new ChangelogDocument([new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION)]);
        $output = $this->renderer()->render($document);

        self::assertStringContainsString('# Changelog', $output);
        self::assertStringContainsString('## [Unreleased]', $output);
        self::assertStringNotContainsString('[unreleased]:', $output);
    }

    #[Test]
    public function renderHandlesPublishedHeadingsAndMultipleEntrySections(): void
    {
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $release = new ChangelogRelease('1.0.0')
            ->withEntry(ChangelogEntryType::Added, 'feature')
            ->withEntry(ChangelogEntryType::Fixed, 'fix');
        $output = $this->renderer()->render(new ChangelogDocument([$unreleased, $release]), '   ');

        self::assertStringContainsString('## [1.0.0]', $output);
        self::assertStringContainsString("### Added\n\n- feature\n\n### Fixed", $output);
        self::assertStringNotContainsString('## [1.0.0] -', $output);
    }

    #[Test]
    public function renderBuildsReferencesForMultiplePrefixedVersions(): void
    {
        $document = new ChangelogDocument([
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
            new ChangelogRelease('V3.0.0', '2026-09-05'),
            new ChangelogRelease('v2.0.0', '2026-08-01'),
            new ChangelogRelease('1.0.0', '2026-01-01')
                ->withEntry(ChangelogEntryType::Added, 'initial'),
        ]);
        $output = $this->renderer()->render($document, 'https://github.com/org/repo.git');

        self::assertStringContainsString('[unreleased]: https://github.com/org/repo/compare/V3.0.0...HEAD', $output);
        self::assertStringContainsString('[V3.0.0]: https://github.com/org/repo/compare/v2.0.0...V3.0.0', $output);
        self::assertStringContainsString('[v2.0.0]: https://github.com/org/repo/compare/v1.0.0...v2.0.0', $output);
        self::assertStringContainsString('[1.0.0]: https://github.com/org/repo/releases/tag/v1.0.0', $output);
    }

    #[Test]
    public function renderNormalizesBothGitSshUrlForms(): void
    {
        $document = new ChangelogDocument([
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
            new ChangelogRelease('1.0.0'),
        ]);

        self::assertStringContainsString(
            'https://github.com/org/repo/releases/tag/v1.0.0',
            $this->renderer()->render($document, 'git@github.com:org/repo.git'),
        );
        self::assertStringContainsString(
            'https://github.com/org/repo/releases/tag/v1.0.0',
            $this->renderer()->render($document, 'ssh://git@github.com/org/repo.git'),
        );
    }

    #[Test]
    #[TestWith(['deploy@git.example.com:org/repo.git'])]
    #[TestWith(['ssh://deploy@git.example.com/org/repo.git'])]
    public function renderNormalizesOtherValidSshUsernames(string $remote): void
    {
        $document = new ChangelogDocument([
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
            new ChangelogRelease('1.0.0'),
        ]);

        self::assertStringContainsString(
            'https://git.example.com/org/repo/releases/tag/v1.0.0',
            $this->renderer()->render($document, $remote),
        );
    }

    #[Test]
    #[TestWith([false])]
    #[TestWith([true])]
    public function renderPreservesCustomReferencesWithAnOrigin(bool $published): void
    {
        $releases = [new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION)];

        if ($published) {
            $releases[] = new ChangelogRelease('1.0.0');
        }

        $references = [
            '[issue]: https://example.com/issues/123',
            '[UNRELEASED]: https://old.example.com/compare/old...HEAD',
            '[1.0.0]: https://old.example.com/releases/tag/v1.0.0',
            'custom reference content',
        ];
        $output = $this->renderer()->render(new ChangelogDocument($releases, $references), 'https://git.example.com/org/repo');

        self::assertStringContainsString('[issue]: https://example.com/issues/123', $output);
        self::assertStringContainsString('custom reference content', $output);
        self::assertStringNotContainsString('[UNRELEASED]: https://old.example.com', $output);

        if ($published) {
            self::assertStringNotContainsString('[1.0.0]: https://old.example.com', $output);
            self::assertStringContainsString('[1.0.0]: https://git.example.com/org/repo/releases/tag/v1.0.0', $output);
        } else {
            self::assertStringContainsString('[1.0.0]: https://old.example.com', $output);
        }
    }

    #[Test]
    public function renderOmitsReferencesWhenThereAreNoPublishedReleases(): void
    {
        $document = new ChangelogDocument([new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION)]);

        self::assertStringNotContainsString(
            '[unreleased]:',
            $this->renderer()->render($document, 'https://github.com/org/repo'),
        );
    }

    #[Test]
    public function renderPreservesParsedReferencesWhenRepositoryUrlIsNull(): void
    {
        $references = [
            '[unreleased]: https://example.com/compare/v1.0.0...HEAD',
            '[1.0.0]: https://example.com/releases/tag/v1.0.0',
        ];
        $document = new ChangelogDocument([
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
            new ChangelogRelease('1.0.0'),
        ], $references);

        $output = $this->renderer()->render($document);

        self::assertStringContainsString(implode("\n", $references), $output);
    }

    #[Test]
    public function renderRemovesHttpsCredentialsFromGeneratedReferences(): void
    {
        $document = new ChangelogDocument([
            new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION),
            new ChangelogRelease('1.0.0'),
        ]);

        $output = $this->renderer()->render(
            $document,
            'https://username:secret@github.com/org/repo.git',
        );

        self::assertStringContainsString('https://github.com/org/repo/releases/tag/v1.0.0', $output);
        self::assertStringNotContainsString('username', $output);
        self::assertStringNotContainsString('secret', $output);
    }

    #[Test]
    public function renderReleaseBodyOmitsTheReleaseHeading(): void
    {
        $release = new ChangelogRelease('1.0.0', '2026-09-05')
            ->withEntry(ChangelogEntryType::Security, 'harden');
        $output = $this->renderer()->renderReleaseBody($release);

        self::assertStringContainsString('### Security', $output);
        self::assertStringContainsString('- harden', $output);
        self::assertStringNotContainsString('## [1.0.0]', $output);
    }

    private function renderer(): MarkdownRenderer
    {
        $entryTypes = $this->prophesize(ChangelogEntryTypesInterface::class);
        $entryTypes->ordered()->willReturn([
            ChangelogEntryType::Added,
            ChangelogEntryType::Changed,
            ChangelogEntryType::Deprecated,
            ChangelogEntryType::Removed,
            ChangelogEntryType::Fixed,
            ChangelogEntryType::Security,
        ]);

        return new MarkdownRenderer($entryTypes->reveal());
    }
}
