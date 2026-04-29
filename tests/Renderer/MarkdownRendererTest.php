<?php

declare(strict_types=1);

/**
 * Standalone changelog domain and CLI runtime for Fast Forward PHP packages.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @author   Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license  https://opensource.org/licenses/MIT MIT License
 *
 * @see      https://github.com/php-fast-forward/changelog
 * @see      https://github.com/php-fast-forward/changelog/issues
 * @see      https://php-fast-forward.github.io/changelog/
 * @see      https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Tests\Renderer;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Entry\ChangelogEntryType;
use FastForward\Changelog\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MarkdownRenderer::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
#[UsesClass(ChangelogEntryType::class)]
final class MarkdownRendererTest extends TestCase
{
    #[Test]
    public function renderWillGenerateChangelogWithHeaderAndUnreleasedSection(): void
    {
        $output = (new MarkdownRenderer())->render(ChangelogDocument::create());

        self::assertStringStartsWith('# Changelog', $output);
        self::assertStringContainsString('## [' . ChangelogDocument::UNRELEASED_VERSION . ']', $output);
        self::assertStringNotContainsString("## [Unreleased]\n\n\n", $output);
        self::assertStringEndsWith("\n", $output);
    }

    #[Test]
    public function renderWillIncludePublishedSectionsAndReferences(): void
    {
        $document = new ChangelogDocument([
            (new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION))->withEntry(
                ChangelogEntryType::Changed,
                'Pending change'
            ),
            (new ChangelogRelease('1.1.0', '2026-04-02'))->withEntry(ChangelogEntryType::Changed, 'Feature B'),
            (new ChangelogRelease('1.0.0', '2026-04-01'))->withEntry(ChangelogEntryType::Added, 'Feature A'),
        ]);

        $output = (new MarkdownRenderer())->render($document, 'git@github.com:php-fast-forward/changelog.git');

        self::assertStringContainsString('## [1.1.0] - 2026-04-02', $output);
        self::assertStringContainsString('### Added', $output);
        self::assertStringContainsString('### Changed', $output);
        self::assertStringContainsString(
            '[unreleased]: https://github.com/php-fast-forward/changelog/compare/v1.1.0...HEAD',
            $output,
        );
        self::assertStringContainsString(
            '[1.1.0]: https://github.com/php-fast-forward/changelog/compare/v1.0.0...v1.1.0',
            $output,
        );
    }

    #[Test]
    public function renderReleaseBodyWillOmitTheReleaseHeading(): void
    {
        $release = (new ChangelogRelease('1.2.0', '2026-04-19'))
            ->withEntry(ChangelogEntryType::Added, 'Ship changelog automation');

        $output = (new MarkdownRenderer())->renderReleaseBody($release);

        self::assertStringNotContainsString('## [1.2.0]', $output);
        self::assertStringContainsString('### Added', $output);
        self::assertStringContainsString('- Ship changelog automation', $output);
    }

    #[Test]
    public function renderWillNormalizeSshRepositoryUrlsAndTrimTrailingGitSuffix(): void
    {
        $document = new ChangelogDocument([
            (new ChangelogRelease('1.2.0', '2026-04-19'))->withEntry(
                ChangelogEntryType::Added,
                'Ship changelog automation',
            ),
        ]);

        $output = (new MarkdownRenderer())->render($document, 'ssh://git@github.com/php-fast-forward/changelog.git');

        self::assertStringContainsString(
            '[unreleased]: https://github.com/php-fast-forward/changelog/compare/v1.2.0...HEAD',
            $output,
        );
        self::assertStringContainsString(
            '[1.2.0]: https://github.com/php-fast-forward/changelog/releases/tag/v1.2.0',
            $output,
        );
    }
}
