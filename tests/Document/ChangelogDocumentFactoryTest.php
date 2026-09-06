<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Document;

use FastForward\Changelog\Document\ChangelogDocument;
use FastForward\Changelog\Document\ChangelogDocumentFactory;
use FastForward\Changelog\Document\ChangelogRelease;
use FastForward\Changelog\Document\ChangelogReleaseFactoryInterface;
use Prophecy\PhpUnit\ProphecyTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChangelogDocumentFactory::class)]
#[UsesClass(ChangelogDocument::class)]
#[UsesClass(ChangelogRelease::class)]
final class ChangelogDocumentFactoryTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function createAddsUnreleasedWhenItIsMissing(): void
    {
        $published = new ChangelogRelease('1.0.0');
        $unreleased = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION)->willReturn($unreleased)->shouldBeCalledOnce();

        $references = ['[1.0.0]: https://example.com/releases/tag/v1.0.0'];
        $document = (new ChangelogDocumentFactory($releaseFactory->reveal()))->create([$published], $references);

        self::assertSame([$unreleased, $published], $document->getReleases());
        self::assertSame($references, $document->getReferences());
    }

    #[Test]
    public function createKeepsOnlyTheFirstUnreleasedSectionAtTheTop(): void
    {
        $first = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $duplicate = new ChangelogRelease(ChangelogDocument::UNRELEASED_VERSION);
        $published = new ChangelogRelease('1.0.0');
        $releaseFactory = $this->prophesize(ChangelogReleaseFactoryInterface::class);
        $releaseFactory->create(ChangelogDocument::UNRELEASED_VERSION)->shouldNotBeCalled();

        $document = (new ChangelogDocumentFactory($releaseFactory->reveal()))->create([$published, $first, $duplicate]);

        self::assertSame([$first, $published], $document->getReleases());
    }
}
