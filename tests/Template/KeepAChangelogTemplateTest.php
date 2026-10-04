<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Template;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Template\KeepAChangelogTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(KeepAChangelogTemplate::class)]
#[UsesClass(Category::class)]
final class KeepAChangelogTemplateTest extends TestCase
{
    #[Test]
    public function returnsTheConfiguredPresentation(): void
    {
        $template = new KeepAChangelogTemplate('pt-BR', 'intro', '## {version}', '## {version} {date}', ['added' => '### Novidades'], '## Pendente', 'Sem notas');
        self::assertSame('pt-BR', $template->getLocale());
        self::assertSame('intro', $template->introduction());
        self::assertSame('## 1.0.0', $template->releaseHeading('1.0.0', null));
        self::assertSame('## 1.0.0 2026-10-03', $template->releaseHeading('1.0.0', '2026-10-03'));
        self::assertSame('### Novidades', $template->categoryHeading('added'));
        self::assertSame('## Pendente', $template->unreleasedHeading());
        self::assertSame('Sem notas', $template->missingNotes());
    }
}
