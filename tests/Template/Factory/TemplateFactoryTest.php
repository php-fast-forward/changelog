<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Template\Factory;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Template\Factory\TemplateFactory;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateFactory::class)]
#[UsesClass(KeepAChangelogTemplate::class)]
#[UsesClass(Category::class)]
#[UsesClass(HistoryCodec::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class TemplateFactoryTest extends TestCase
{
    #[Test]
    #[TestWith(['en', '### Added', '## [Unreleased]'])]
    #[TestWith(['pt-BR', '### Adicionado', '## [Não publicado]'])]
    public function createsVerifiedBuiltinLocales(string $locale, string $category, string $unreleased): void
    {
        $template = new TemplateFactory()->create($locale);
        self::assertSame($locale, $template->getLocale());
        self::assertSame($category, $template->categoryHeading('added'));
        self::assertSame($unreleased, $template->unreleasedHeading());
        self::assertStringContainsString('keepachangelog.com', $template->introduction());
        self::assertNotEmpty($template->missingNotes());
    }

    #[Test]
    public function acceptsExplicitPartialPresentationOverrides(): void
    {
        $template = new TemplateFactory()->create('en', ['introduction' => '# History', 'category_headings' => ['added' => '### Features'], 'release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version}: {date}', 'no_notes' => 'No notes']);
        self::assertSame('# History', $template->introduction());
        self::assertSame('### Features', $template->categoryHeading('added'));
        self::assertSame('### Fixed', $template->categoryHeading('fixed'));
        self::assertSame('## Release 1.0.0: 2026-10-03', $template->releaseHeading('1.0.0', '2026-10-03'));
        self::assertSame('No notes', $template->missingNotes());
    }

    #[Test]
    #[TestWith(['## {version}{date}'])]
    #[TestWith(['## {date}{version}'])]
    #[TestWith(['## {version}.{date}'])]
    #[TestWith(['## {date}.{version}'])]
    #[TestWith(['## {version}-{date}'])]
    #[TestWith(['## {date}-{version}'])]
    #[TestWith(['## {version}on{date}'])]
    #[TestWith(['## {date}on{version}'])]
    public function rejectsAmbiguousMixedPlaceholderBoundariesBeforeRendering(string $heading): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('literal delimiter outside valid version/date characters');
        new TemplateFactory()->create('en', ['release_heading_dated' => $heading]);
    }

    #[Test]
    #[TestWith(['## {version} {date}'])]
    #[TestWith(['## [{version}]{date}'])]
    #[TestWith(['## {date}[{version}]'])]
    #[TestWith(['## {version}:{date}'])]
    #[TestWith(['## {date}/{version}'])]
    #[TestWith(['## {version} released on {date}'])]
    #[TestWith(['## {version}{version}:{date}'])]
    #[TestWith(['## {date}{date}:{version}'])]
    public function separatedLiteralAndRepeatedSamePlaceholderFormsRoundTripThroughTheActualCodec(string $heading): void
    {
        $template = new TemplateFactory()->create('en', ['release_heading_dated' => $heading]);
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(static fn(array $releases, string $prefix, string $references): HistoryDocument => new HistoryDocument($releases, $prefix, $references));
        $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
        $releases->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $source, string $body, ?string $heading, string $ending): HistoryRelease => new HistoryRelease($version, $date, $source, $body, $heading, $ending));
        $exceptions = $this->createStub(HistoryExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        $codec = new HistoryCodec($documents, $releases, $exceptions);
        $version = '1.2.3-beta.1+build.7';
        $date = '2026-10-03';
        $body = "Exact release notes.\n";
        $markdown = $codec->render(new HistoryDocument([new HistoryRelease($version, $date, body: $body)]), $template);
        $document = $codec->parse($markdown, $template);

        self::assertCount(1, $document->getReleases());
        self::assertSame($version, $document->getReleases()[0]->getVersion());
        self::assertSame($date, $document->getReleases()[0]->getDate());
        self::assertSame($body, $codec->notes($document, $version));
    }

    #[Test]
    #[TestWith([['unreleased_heading' => '## [1.2.3]']])]
    #[TestWith([['unreleased_heading' => '## [v1.2.3]']])]
    #[TestWith([['unreleased_heading' => '## [V1.2.3-beta.1+build.7]']])]
    #[TestWith([['unreleased_heading' => '## [1.2.3] - 2026-10-03']])]
    #[TestWith([['unreleased_heading' => '## [1.2.3] - 2000-02-29']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 1.2.3']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 1.2.3 [YANKED]']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 1.2.3+01']])]
    #[TestWith([['release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## Release 1.2.3: 2026-10-03']])]
    #[TestWith([['release_heading_dated' => '## {date}/{version}', 'unreleased_heading' => '## 2026-10-03/V1.2.3-beta.1+build.7']])]
    #[TestWith([['release_heading' => '## {version} vs {version}', 'unreleased_heading' => '## 1.2.3 vs 1.2.3']])]
    #[TestWith([['release_heading' => '## {version}{version}', 'unreleased_heading' => '## 1.2.31.2.3']])]
    #[TestWith([['release_heading_dated' => '## {version}: {date}/{date}', 'unreleased_heading' => '## 1.2.3: 2026-10-03/2026-10-03']])]
    #[TestWith([['release_heading' => '## {version}:{date}', 'unreleased_heading' => '## 1.2.3:']])]
    #[TestWith([['release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## [1.2.3]']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => '## [V1.2.3](https://example.test/release) - 2026-10-03 [REMOVIDO]']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => '## [1.2.3](https://example.test/release) [YANKED]']])]
    #[TestWith([['release_heading' => '## Release {version}', 'unreleased_heading' => "##  [1.2.3]\t"]])]
    public function rejectsPendingHeadingsThatAlsoIdentifyValidCustomOrBuiltinReleases(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unreleased heading must not also identify a valid release heading');
        new TemplateFactory()->create('en', $overrides);
    }

    #[Test]
    public function rejectsActualRenderedReleaseAndPendingHeadingCollisionBeforeCreatingTemplate(): void
    {
        $unsafe = new KeepAChangelogTemplate('en', '# History', '## Release {version}', '## Release {version}: {date}', ['added' => '### Added', 'changed' => '### Changed', 'deprecated' => '### Deprecated', 'removed' => '### Removed', 'fixed' => '### Fixed', 'security' => '### Security'], '## Release 1.2.3', 'No notes');
        self::assertSame($unsafe->unreleasedHeading(), $unsafe->releaseHeading('1.2.3', null));
        $codec = $this->codec();
        $rendered = $codec->render(new HistoryDocument([new HistoryRelease('1.2.3', body: "Concrete release notes.\n")]), $unsafe);
        self::assertSame('unreleased', $codec->parse($rendered, $unsafe)->getReleases()[0]->getVersion());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unreleased heading must not also identify a valid release heading');
        new TemplateFactory()->create('en', ['release_heading' => '## Release {version}', 'unreleased_heading' => $unsafe->unreleasedHeading()]);
    }

    #[Test]
    #[TestWith(['en', ['unreleased_heading' => '## [unreleased]']])]
    #[TestWith(['en', ['unreleased_heading' => '## [Não publicado]']])]
    #[TestWith(['pt-BR', ['unreleased_heading' => '## [não publicado]']])]
    #[TestWith(['pt-BR', ['unreleased_heading' => '## [Unreleased]']])]
    #[TestWith(['pt-BR', ['release_heading' => '## Versão {version}', 'release_heading_dated' => '## Versão {version} em {date}', 'unreleased_heading' => '## Próxima versão']])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## release 1.2.3']])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 01.2.3']])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 1.2.3-01']])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'unreleased_heading' => '## Release 1.2.3 next']])]
    #[TestWith(['en', ['release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## Release 1.2.3: 2026-02-30']])]
    #[TestWith(['en', ['release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## Release 1.2.3: 1900-02-29']])]
    #[TestWith(['en', ['release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## Release 1.2.3: 0000-02-28']])]
    #[TestWith(['en', ['release_heading_dated' => '## Release {version}: {date}', 'unreleased_heading' => '## Release 1.2.3: 2026-2-03']])]
    #[TestWith(['en', ['release_heading' => '## Release {version}', 'unreleased_heading' => '## [1.2.3](https://example.test/release) - 2026-02-30 [YANKED]']])]
    #[TestWith(['en', ['release_heading' => '## {version} vs {version}', 'unreleased_heading' => '## 1.2.3 vs 1.2.4']])]
    #[TestWith(['en', ['release_heading_dated' => '## {version}: {date}/{date}', 'unreleased_heading' => '## 1.2.3: 2026-10-03/2026-10-04']])]
    public function preservesLocalizedPendingHeadingsAndNonreleaseNearMisses(string $locale, array $overrides): void
    {
        $template = new TemplateFactory()->create($locale, $overrides);
        self::assertSame($overrides['unreleased_heading'], $template->unreleasedHeading());
        $codec = $this->codec();
        $markdown = $codec->render(new HistoryDocument([
            new HistoryRelease('unreleased', body: "Pending notes.\n"),
            new HistoryRelease('1.2.3', '2026-10-03', body: "Dated release notes.\n"),
            new HistoryRelease('1.2.4', body: "Undated release notes.\n"),
        ]), $template);
        $parsed = $codec->parse($markdown, $template);
        self::assertSame(['unreleased', '1.2.3', '1.2.4'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame('2026-10-03', $parsed->getRelease('1.2.3')->getDate());
        self::assertSame("Pending notes.\n", $codec->notes($parsed, 'unreleased'));
        self::assertSame("Dated release notes.\n", $codec->notes($parsed, '1.2.3'));
        self::assertSame("Undated release notes.\n", $codec->notes($parsed, '1.2.4'));
    }

    #[Test]
    public function rejectsUnknownLocales(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateFactory()->create('unknown');
    }

    #[Test]
    #[TestWith([['unknown' => 'text']])]
    #[TestWith([['introduction' => null]])]
    #[TestWith([['introduction' => '']])]
    #[TestWith([['category_headings' => 'text']])]
    #[TestWith([['category_headings' => ['feature' => '### Feature']]])]
    #[TestWith([['category_headings' => ['added' => 42]]])]
    #[TestWith([['category_headings' => ['added' => '## Wrong level']]])]
    #[TestWith([['category_headings' => ['added' => "### Added\nother"]]])]
    #[TestWith([['category_headings' => ['added' => '### ']]])]
    #[TestWith([['release_heading' => '## Release']])]
    #[TestWith([['release_heading_dated' => '## Release {version}']])]
    public function rejectsInvalidPresentationSettings(array $overrides): void
    {
        $this->expectException(InvalidArgumentException::class);
        new TemplateFactory()->create('en', $overrides);
    }
    /** Exercises Markdown identity parsing with injected construction boundaries and no external state. */
    private function codec(): HistoryCodec
    {
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(static fn(array $releases, string $prefix, string $references): HistoryDocument => new HistoryDocument($releases, $prefix, $references));
        $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
        $releases->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $source, string $body, ?string $heading, string $ending): HistoryRelease => new HistoryRelease($version, $date, $source, $body, $heading, $ending));
        $exceptions = $this->createStub(HistoryExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));
        return new HistoryCodec($documents, $releases, $exceptions);
    }

}
