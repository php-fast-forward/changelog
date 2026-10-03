<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Template\Factory;

use FastForward\Changelog\Changeset\Category;
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
}
