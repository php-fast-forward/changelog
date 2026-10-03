<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\History;

use FastForward\Changelog\History\Factory\HistoryDocumentFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryExceptionFactoryInterface;
use FastForward\Changelog\History\Factory\HistoryReleaseFactoryInterface;
use FastForward\Changelog\History\HistoryCodec;
use FastForward\Changelog\History\HistoryDocument;
use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Template\TemplateInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(HistoryCodec::class)]
#[UsesClass(HistoryDocument::class)]
#[UsesClass(HistoryRelease::class)]
final class HistoryCodecTest extends TestCase
{
    #[Test]
    public function preservesCrLfDescriptionsFencesReferencesAndFooterBoilerplate(): void
    {
        $body = "\r\n### Added\r\n\r\n- [Feature][issue]\r\n  nested prose\r\n\r\n```markdown\r\n## [99.0.0] - 2026-01-01\r\n[example]: https://example.test\r\n```\r\n\r\n";
        $previous = "\r\n### Unknown presentation\r\n\r\nArbitrary old release prose.\r\n\r\n~~~text\r\n## [88.0.0]\r\n~~~\r\n\r\n";
        $prefix = "# Project history\r\n\r\nCustom introductory prose.\r\n\r\n";
        $footer = "[issue]: https://example.test/issues/123\r\n[1.0.0]: https://example.test/tag/v1.0.0\r\n\r\nFooter license prose.\r\n";
        $markdown = $prefix . "## [Unreleased]\r\n" . $body . "## [v1.0.0] - 2026-01-01\r\n" . $previous . $footer;
        $codec = $this->codec();
        $document = $codec->parse($markdown);

        self::assertCount(2, $document->getReleases());
        self::assertSame($prefix, $document->getPrefix());
        self::assertSame($footer, $document->getReferences());
        self::assertSame($previous, $codec->notes($document, '1.0.0'));
        self::assertSame($body, $codec->notes($document, 'unreleased'));
        self::assertSame('2026-01-01', $document->getRelease('1.0.0')->getDate());
        self::assertSame($markdown, $codec->render($document, $this->template(), true));
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['Unstructured raw history without supported release headings.'])]
    #[TestWith(["# Custom\n\n[link]: https://example.test\n\nfooter\n"])]
    public function retainsUnstructuredHistory(string $markdown): void
    {
        $codec = $this->codec();
        $document = $codec->parse($markdown);
        self::assertSame([], $document->getReleases());

        if ('' !== $markdown) {
            self::assertSame($markdown, $codec->render($document, $this->template(), true));
        } else {
            self::assertStringContainsString('Intro pt-BR', $codec->render($document, $this->template()));
        }
    }

    #[Test]
    public function customMarkedPresentationRoundTripsRawVersionLikeHeadings(): void
    {
        $codec = $this->codec();
        $body = "\n### Added\n\nRaw release text with [links](https://example.test).\n\n## [9.0.0]\nThis is part of the description.\n\n```md\n### Added\n```\n";
        $document = new HistoryDocument([new HistoryRelease('1.2.3', '2026-10-03', 'published_at', $body)]);
        $output = $codec->render($document, $this->template('custom'));
        $parsed = $codec->parse($output);

        self::assertCount(1, $parsed->getReleases());
        self::assertSame('published_at', $parsed->getRelease('1.2.3')->getDateSource());
        self::assertStringContainsString('### Custom added', $codec->notes($parsed, '1.2.3'));
        self::assertStringContainsString('## [9.0.0]', $codec->notes($parsed, '1.2.3'));
        self::assertStringContainsString("```md\n### Added\n```", $codec->notes($parsed, '1.2.3'));
        self::assertSame($output, $codec->render($parsed, $this->template('custom'), true));
        self::assertSame($output, $codec->render($parsed, $this->template('custom')));
    }

    #[Test]
    public function convertsLocalizedStructureBackToEnglishWithoutChangingDescriptions(): void
    {
        $codec = $this->codec();
        $document = $codec->parse("# Custom prose stays\n\n## [Não publicado]\n\n### Adicionado\n- descrição permanece\n\n## [1.0.0] - 2026-10-03 [REMOVIDO]\n\n### Corrigido\n- correction\n### Unknown\nProse remains.\n");
        $output = $codec->render($document, $this->template('en'));

        self::assertStringStartsWith('# Custom prose stays', $output);
        self::assertStringContainsString('## [Unreleased]', $output);
        self::assertStringContainsString('### Added', $output);
        self::assertStringContainsString('### Fixed', $output);
        self::assertStringContainsString('descrição permanece', $output);
        self::assertStringContainsString('### Unknown', $output);
        self::assertStringContainsString('[REMOVIDO]', $output);
    }

    #[Test]
    public function replacesOnlyKnownIntroductoryBoilerplate(): void
    {
        $codec = $this->codec();
        $prefix = "# Changelog\n\nAll notable changes to this project will be documented in this file.\n\nThe format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),\nand this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).\n\nAdditional custom introductory prose.\n\n";
        $document = $codec->parse($prefix . "## [1.0.0]\n\n### Added\n- entry\n");
        $output = $codec->render($document, $this->template());
        self::assertStringContainsString('Intro pt-BR', $output);
        self::assertStringContainsString('Additional custom introductory prose.', $output);
        self::assertStringNotContainsString('All notable changes', $output);
    }

    #[Test]
    public function newSectionsPreserveRawNotesAndDateProvenanceIncrementally(): void
    {
        $codec = $this->codec();
        $document = new HistoryDocument([new HistoryRelease('1.0.0', null, null, 'raw notes')], " \n");
        $output = $codec->render($document, $this->template(), true);
        $parsed = $codec->parse($output);
        self::assertSame("raw notes\n", $codec->notes($parsed, '1.0.0'));
        self::assertNull($parsed->getRelease('1.0.0')->getDate());
        self::assertNull($parsed->getRelease('1.0.0')->getDateSource());
    }

    #[Test]
    public function byteFramingPreservesEmbeddedDelimitersAndFalseReleaseHeadings(): void
    {
        $body = "First paragraph.\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9] - 2020-01-01\n"
            . "<!-- fast-forward-changelog:release {\"version\":\"8.8.8\"} -->\n## Fake release\n"
            . "<!-- fast-forward-changelog:end-release -->\n[internal]: https://example.test\nLast paragraph.\n";
        $codec = $this->codec();
        $output = $codec->render(new HistoryDocument([new HistoryRelease('1.0.0', body: $body)]), $this->template('en'), true);
        $parsed = $codec->parse($output);

        self::assertSame(['1.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame($body, $codec->notes($parsed, '1.0.0'));
        self::assertSame('', $parsed->getReferences());
        self::assertSame($output, $codec->render($parsed, $this->template('en'), true));
        self::assertSame($output, $codec->render($parsed, $this->template('en')));
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['Unicode descrição e segurança 🐘'])]
    #[TestWith(["Unicode descrição e segurança 🐘\n"])]
    #[TestWith(["Descrição com espaços.  \r\n\r\nÚltima linha.\r\n"])]
    public function countsActualUnicodeAndNewlineBytes(string $body): void
    {
        $codec = $this->codec();
        $expected = '' === $body || str_ends_with($body, "\n") ? $body : $body . "\n";
        $output = $codec->render(new HistoryDocument([new HistoryRelease('1.0.0', body: $body)]), $this->template('en'), true);
        self::assertSame(1, preg_match('/<!-- fast-forward-changelog:release (.+) -->\n/', $output, $matches));
        $metadata = json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(strlen($expected), $metadata['body_length']);
        self::assertSame($expected, $codec->notes($codec->parse($output), '1.0.0'));
    }

    #[Test]
    #[TestWith([null])]
    #[TestWith([true])]
    #[TestWith(['5'])]
    #[TestWith([1.5])]
    #[TestWith([-1])]
    #[TestWith([0])]
    #[TestWith([1])]
    #[TestWith([4])]
    #[TestWith([6])]
    #[TestWith([PHP_INT_MAX])]
    public function rejectsMalformedOrMismatchedBodyLengths(mixed $length): void
    {
        $metadata = json_encode(['version' => '1.0.0', 'body_length' => $length], JSON_THROW_ON_ERROR);
        $markdown = '<!-- fast-forward-changelog:release ' . $metadata . " -->\n## [1.0.0]\ntext\n<!-- fast-forward-changelog:end-release -->\n";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body_length');
        $this->codec()->parse($markdown);
    }

    #[Test]
    public function rejectsAnAbsentClosingDelimiterAfterAnExactlySizedBody(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('body_length');
        $this->codec()->parse("<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"body_length\":5} -->\n## [1.0.0]\ntext\n");
    }

    #[Test]
    public function acceptsSafeLegacyFramingAndMigratesItWithoutChangingNotes(): void
    {
        $body = "Legacy notes.\n\n```md\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9]\n```\n";
        $legacy = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\n" . $body
            . "<!-- fast-forward-changelog:end-release -->\n";
        $codec = $this->codec();
        $parsed = $codec->parse($legacy);
        self::assertSame($body, $codec->notes($parsed, '1.0.0'));
        $migrated = $codec->render($parsed, $this->template('en'));
        self::assertStringContainsString('"body_length":' . strlen($body), $migrated);
        self::assertSame($body, $codec->notes($codec->parse($migrated), '1.0.0'));
    }

    #[Test]
    public function rejectsAmbiguousLegacyDelimitersInsteadOfTruncatingNotes(): void
    {
        $legacy = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\nFirst paragraph.\n"
            . "<!-- fast-forward-changelog:end-release -->\n## [9.9.9] - 2020-01-01\nInjected paragraph.\n"
            . "<!-- fast-forward-changelog:end-release -->\n";
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous closing delimiters');
        $this->codec()->parse($legacy);
    }

    #[Test]
    public function mixedLegacyAndByteFramedSectionsRetainEmbeddedControlText(): void
    {
        $body = "New body.\n<!-- fast-forward-changelog:end-release -->\n## [9.9.9]\n";
        $metadata = json_encode(['version' => '2.0.0', 'body_length' => strlen($body)], JSON_THROW_ON_ERROR);
        $markdown = "<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n## [1.0.0]\nLegacy body.\n"
            . "<!-- fast-forward-changelog:end-release -->\n<!-- fast-forward-changelog:release " . $metadata . " -->\n## [2.0.0]\n" . $body
            . "<!-- fast-forward-changelog:end-release -->\n";
        $codec = $this->codec();
        $parsed = $codec->parse($markdown);
        self::assertSame(['1.0.0', '2.0.0'], array_map(static fn(HistoryRelease $release): string => $release->getVersion(), $parsed->getReleases()));
        self::assertSame("Legacy body.\n", $codec->notes($parsed, '1.0.0'));
        self::assertSame($body, $codec->notes($parsed, '2.0.0'));
    }

    #[Test]
    public function supportsEmptyLinkedAndUndatedSections(): void
    {
        $codec = $this->codec();
        $document = $codec->parse("## [1.0.0](https://example.test/releases/v1.0.0)\n");
        self::assertSame('', $codec->notes($document, '1.0.0'));
        $output = $codec->render(new HistoryDocument([new HistoryRelease('unreleased')]), $this->template());
        self::assertSame('', $codec->notes($codec->parse($output), 'unreleased'));
    }

    #[Test]
    #[TestWith(["<!-- fast-forward-changelog:release {} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release 42 -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release invalid-json -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"date\":42} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"date_source\":42} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\",\"unknown\":true} -->\n## Custom\n<!-- fast-forward-changelog:end-release -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\ntext\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n<!-- fast-forward-changelog:release {\"version\":\"2.0.0\"} -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n"])]
    #[TestWith(["<!-- fast-forward-changelog:release {\"version\":\"1.0.0\"} -->\n\n## Custom\nbody\n"])]
    public function rejectsMalformedOrUnterminatedReleaseMarkers(string $markdown): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->parse($markdown);
    }

    #[Test]
    #[TestWith(["<!-- fast-forward-changelog:category added -->\nprose\n"])]
    #[TestWith(["<!-- fast-forward-changelog:category added -->\n"])]
    #[TestWith(["```md\nUnclosed example\n"])]
    public function refusesAmbiguousReformattingBeforeAnyIo(string $body): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->render(new HistoryDocument([new HistoryRelease('1.0.0', null, null, $body)]), $this->template());
    }

    #[Test]
    public function missingReleaseHasAnExplicitDiagnostic(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->codec()->notes(new HistoryDocument(), '9.0.0');
    }

    /** Returns the codec with all service-construction boundaries replaced by doubles. */
    private function codec(): HistoryCodec
    {
        $documents = $this->createStub(HistoryDocumentFactoryInterface::class);
        $documents->method('create')->willReturnCallback(static fn(array $releases, string $prefix, string $references): HistoryDocument => new HistoryDocument($releases, $prefix, $references));
        $releases = $this->createStub(HistoryReleaseFactoryInterface::class);
        $releases->method('create')->willReturnCallback(static fn(string $version, ?string $date, ?string $dateSource, string $body, ?string $heading, string $ending): HistoryRelease => new HistoryRelease(preg_replace('/^[vV](?=\d)/', '', $version), $date, $dateSource, $body, $heading, $ending));
        $errors = $this->createStub(HistoryExceptionFactoryInterface::class);
        $errors->method('invalid')->willReturnCallback(static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message));

        return new HistoryCodec($documents, $releases, $errors);
    }

    /** Returns a presentation double without invoking the concrete template factory. */
    private function template(string $locale = 'pt-BR'): TemplateInterface
    {
        $template = $this->createStub(TemplateInterface::class);
        $template->method('getLocale')->willReturn($locale);
        $template->method('introduction')->willReturn('Intro ' . $locale);
        $template->method('unreleasedHeading')->willReturn('en' === $locale ? '## [Unreleased]' : '## [Não publicado]');
        $template->method('releaseHeading')->willReturnCallback(static fn(string $version, ?string $date): string => 'custom' === $locale ? '## Release ' . $version . ': ' . $date : '## [' . $version . ']' . (null === $date ? '' : ' - ' . $date));
        $template->method('categoryHeading')->willReturnCallback(static fn(string $category): string => match ($locale) {
            'custom' => '### Custom ' . $category,
            'en' => '### ' . ucfirst($category),
            default => '### ' . ['added' => 'Adicionado', 'fixed' => 'Corrigido', 'changed' => 'Modificado', 'deprecated' => 'Obsoleto', 'removed' => 'Removido', 'security' => 'Segurança'][$category],
        });

        return $template;
    }
}
