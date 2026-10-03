<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Validator;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Validation\Factory\ValidationReportFactoryInterface;
use FastForward\Changelog\Validation\ValidationReport;
use FastForward\Changelog\Validator\ChangesetValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Filesystem\Exception\IOException;

#[CoversClass(ChangesetValidator::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(ChangesetParseResult::class)]
#[UsesClass(ValidationReport::class)]
final class ChangesetValidatorTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function validateRequiresAFragmentWhenNoWaiverExists(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $errors = [
            '@directory' => ['At least one changeset fragment or an explicit waiver is required.'],
        ];
        $expected = new ValidationReport([], $errors, false);
        $store->paths('/repo/.changelog')->willReturn([])->shouldBeCalledOnce();
        $reportFactory->create([], $errors, false)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog'));
    }

    #[Test]
    public function validateAcceptsAWaiverWhenNoFragmentExists(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $expected = new ValidationReport([], [], true);
        $store->paths('/repo/.changelog')->willReturn([])->shouldBeCalledOnce();
        $reportFactory->create([], [], true)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog', true));
    }

    #[Test]
    public function validateRejectsASymbolicDiscoveryRootEvenWithAWaiver(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $errors = [
            '/repo/.changelog' => ['Changeset directory MUST be regular rather than a symbolic link.'],
        ];
        $expected = new ValidationReport([], $errors, true);
        $store->paths('/repo/.changelog')->willReturn(null)->shouldBeCalledOnce();
        $reportFactory->create([], $errors, true)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog', true));
    }

    #[Test]
    public function validatePreservesMultipleFragmentsFromTheSamePullRequest(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $firstPath = '/repo/.changelog/api.md';
        $secondPath = '/repo/.changelog/docs.md';
        $first = new Changeset('api.md', Category::Changed, 10, 77, '@coisa', 'Change API.');
        $second = new Changeset('docs.md', Category::Fixed, 10, 77, '@coisa', 'Fix docs.');
        $firstResult = new ChangesetParseResult('api.md', $first, []);
        $secondResult = new ChangesetParseResult('docs.md', $second, []);
        $hashes = [$firstPath => hash('sha256', 'first contents'), $secondPath => hash('sha256', 'second contents')];
        $expected = new ValidationReport([$first, $second], [], false, $hashes);
        $store->paths('/repo/.changelog')->willReturn([$firstPath, $secondPath])->shouldBeCalledOnce();
        $store->read($firstPath)->willReturn('first contents')->shouldBeCalledOnce();
        $store->read($secondPath)->willReturn('second contents')->shouldBeCalledOnce();
        $parser->parse($firstPath, 'first contents')->willReturn($firstResult)->shouldBeCalledOnce();
        $parser->parse($secondPath, 'second contents')->willReturn($secondResult)->shouldBeCalledOnce();
        $reportFactory->create([$first, $second], [], false, $hashes)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog'));
        self::assertSame($hashes, $expected->hashes);
    }

    #[Test]
    public function validateAccumulatesWaiverAndParseErrorsWithoutDiscardingValidSiblings(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $validPath = '/repo/.changelog/valid.md';
        $invalidPath = '/repo/.changelog/invalid.md';
        $valid = new Changeset('valid.md', Category::Added, null, 99, '@coisa', 'Add feature.');
        $validResult = new ChangesetParseResult('valid.md', $valid, []);
        $invalidResult = new ChangesetParseResult('invalid.md', null, ['bad category', 'empty body']);
        $errors = [
            '@waiver' => ['A waiver cannot be combined with changeset fragments.'],
            'invalid.md' => ['bad category', 'empty body'],
        ];
        $hashes = [$validPath => hash('sha256', 'valid contents')];
        $expected = new ValidationReport([$valid], $errors, true, $hashes);
        $store->paths('/repo/.changelog')
            ->willReturn([$validPath, $invalidPath])
            ->shouldBeCalledOnce();
        $store->read($validPath)->willReturn('valid contents')->shouldBeCalledOnce();
        $store->read($invalidPath)->willReturn('invalid contents')->shouldBeCalledOnce();
        $parser->parse($validPath, 'valid contents')->willReturn($validResult)->shouldBeCalledOnce();
        $parser->parse($invalidPath, 'invalid contents')->willReturn($invalidResult)->shouldBeCalledOnce();
        $reportFactory->create([$valid], $errors, true, $hashes)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog', true));
    }

    #[Test]
    public function validateRejectsNestedMarkdownBeforeReadingIt(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $path = '/repo/.changelog/nested/change.md';
        $errors = [$path => ['Changeset Markdown must be a direct child of its configured directory.']];
        $expected = new ValidationReport([], $errors, false);
        $store->paths('/repo/.changelog')->willReturn([$path])->shouldBeCalledOnce();
        $store->read($path)->shouldNotBeCalled();
        $parser->parse($path, '')->shouldNotBeCalled();
        $reportFactory->create([], $errors, false)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog'));
    }

    #[Test]
    public function validateRejectsAPathOutsideTheConfiguredDirectory(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $path = '/other/change.md';
        $errors = [$path => ['Changeset Markdown must be a direct child of its configured directory.']];
        $expected = new ValidationReport([], $errors, false);
        $store->paths('/repo/.changelog')->willReturn([$path])->shouldBeCalledOnce();
        $store->read($path)->shouldNotBeCalled();
        $reportFactory->create([], $errors, false)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog'));
    }

    #[Test]
    public function validateRejectsASymbolicLinkWithoutParsingItsTarget(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $path = '/repo/.changelog/link.md';
        $errors = [$path => ['Changeset fragments MUST be regular files rather than symbolic links.']];
        $expected = new ValidationReport([], $errors, false);
        $store->paths('/repo/.changelog')->willReturn([$path])->shouldBeCalledOnce();
        $store->read($path)->willReturn(null)->shouldBeCalledOnce();
        $parser->parse($path, '')->shouldNotBeCalled();
        $reportFactory->create([], $errors, false)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        self::assertSame($expected, $validator->validate('/repo/.changelog'));
    }

    #[Test]
    public function validatePropagatesFilesystemFailures(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $parser = $this->prophesize(ChangesetParserInterface::class);
        $reportFactory = $this->prophesize(ValidationReportFactoryInterface::class);
        $path = '/repo/.changelog/unreadable.md';
        $store->paths('/repo/.changelog')->willReturn([$path])->shouldBeCalledOnce();
        $store->read($path)->willThrow(new IOException('permission denied'))->shouldBeCalledOnce();
        $parser->parse($path, '')->shouldNotBeCalled();
        $validator = new ChangesetValidator($store->reveal(), $parser->reveal(), $reportFactory->reveal());

        $this->expectException(IOException::class);
        $this->expectExceptionMessage('permission denied');

        $validator->validate('/repo/.changelog');
    }
    #[Test]
    public function inventoryModeAcceptsAnEmptyDirectoryWithoutInventingAWaiver(): void
    {
        $store = $this->prophesize(ChangesetStoreInterface::class);
        $store->paths('/repo/.changelog')->willReturn([])->shouldBeCalledOnce();
        $reports = $this->prophesize(ValidationReportFactoryInterface::class);
        $expected = new ValidationReport([], [], false);
        $reports->create([], [], false)->willReturn($expected)->shouldBeCalledOnce();
        $validator = new ChangesetValidator($store->reveal(), $this->prophesize(ChangesetParserInterface::class)->reveal(), $reports->reveal());
        self::assertSame($expected, $validator->validate('/repo/.changelog', false, false));
    }

}
