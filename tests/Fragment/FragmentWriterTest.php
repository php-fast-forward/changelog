<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Fragment;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Parser\ChangesetParserInterface;
use FastForward\Changelog\Changeset\Renderer\ChangesetRendererInterface;
use FastForward\Changelog\Changeset\Store\ChangesetStoreInterface;
use FastForward\Changelog\Changeset\Store\WriteResult;
use FastForward\Changelog\Fragment\FragmentWriter;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Version\VersionImpact;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;
use RuntimeException;

#[UsesClass(ReleaseOptions::class)]
#[CoversClass(FragmentWriter::class)]
#[UsesClass(Category::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(ChangesetParseResult::class)]
final class FragmentWriterTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    public function createsCanonicalOptionalMetadataWithAnExplicitLowerImpactWithoutGitOrRandomCalls(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $path = '/repo/.changelog/dependency.md';
        $body = "    preserved  \nsecond  ";
        $fragment = new Changeset('dependency.md', Category::Changed, 1, 2, 'coisa', $body, VersionImpact::Patch);
        $input = "---\ncategory: changed\ntype: patch\nissue: 1\npull_request: 2\nauthor: \"@coisa\"\n---\n\n{$body}";
        $parser->parse($path, $input)->willReturn(
            new ChangesetParseResult('dependency.md', $fragment, []),
        )->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical markdown')->shouldBeCalledOnce();
        $store->write($path, 'canonical markdown')->willReturn(WriteResult::Created)->shouldBeCalledOnce();
        $identifiers->generate()->shouldNotBeCalled();
        $git->commitFragment(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        self::assertSame(
            $path,
            $this->writer(...[$parser, $renderer, $store, $identifiers, $git])->add(new ReleaseOptions(
                '/repo',
            ), $body, type: 'patch', name: 'dependency.md', issue: 1, pullRequest: 2, author: '@coisa'),
        );
    }

    #[Test]
    public function generatedCollisionsRetryAndAnOptionalCommitReceivesOnlyTheCreatedRelativePath(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $identifiers->generate()->willReturn('first.md', 'second.md')->shouldBeCalledTimes(2);
        foreach (['first.md' => WriteResult::Existing, 'second.md' => WriteResult::Created] as $id => $result) {
            $fragment = new Changeset($id, Category::Deprecated, null, null, null, 'Body.');
            $parser->parse(
                '/repo/.changelog/' . $id,
                "---\ncategory: deprecated\ntype: minor\n---\n\nBody.",
            )->willReturn(
                new ChangesetParseResult($id, $fragment, []),
            )->shouldBeCalledOnce();
            $renderer->render($fragment)->willReturn('canonical ' . $id)->shouldBeCalledOnce();
            $store->write('/repo/.changelog/' . $id, 'canonical ' . $id)->willReturn($result)->shouldBeCalledOnce();
        }
        $git->commitFragment('/repo', '.changelog/second.md', 'record fragment only')->willReturn(
            'sha',
        )->shouldBeCalledOnce();

        self::assertSame(
            '/repo/.changelog/second.md',
            $this->writer($parser, $renderer, $store, $identifiers, $git)->add(new ReleaseOptions(
                '/repo',
            ), 'Body.', 'deprecated', commit: true, commitMessage: 'record fragment only'),
        );
    }

    #[Test]
    #[TestWith([WriteResult::Existing])]
    #[TestWith([WriteResult::UnsafePath])]
    #[TestWith([WriteResult::LockUnavailable])]
    #[TestWith([WriteResult::Available])]
    public function explicitIdentifiersNeverRetryOrOverwriteWhenCreationCannotComplete(WriteResult $result): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(
            new ChangesetParseResult('entry.md', $fragment, []),
        )->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn($result)->shouldBeCalledOnce();
        $identifiers->generate()->shouldNotBeCalled();
        $message = sprintf('Cannot create fragment "/repo/.changelog/entry.md": %s.', $result->value);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($result->value);
        $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
            new ReleaseOptions('/repo'),
            'Body.',
            name: 'entry.md',
        );
    }

    #[Test]
    public function aGeneratedNameCannotEscapeTheFragmentDirectory(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $identifiers->generate()->willReturn('../outside.md')->shouldBeCalledOnce();
        $message = 'Invalid fragment name "../outside.md": use lowercase dash segments and .md.';
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
            new ReleaseOptions('/repo'),
            'Body.',
        );
    }

    #[Test]
    public function schemaFailureIncludesAllDiagnosticsBeforeWriting(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $parser->parse('/repo/.changelog/entry.md', "---\ncategory: invalid\ntype: minor\n---\n\n")->willReturn(
            new ChangesetParseResult('entry.md', null, ['bad category', 'empty body']),
        )->shouldBeCalledOnce();
        $message = 'Invalid fragment "/repo/.changelog/entry.md": bad category empty body';
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad category empty body');
        $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
            new ReleaseOptions('/repo'),
            '',
            'invalid',
            name: 'entry.md',
        );
    }

    #[Test]
    public function fiveGeneratedCollisionsFailWithoutReplacingAnyFile(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $identifiers->generate()->willReturn('entry.md')->shouldBeCalledTimes(5);
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(
            new ChangesetParseResult('entry.md', $fragment, []),
        )->shouldBeCalledTimes(
            5,
        );
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledTimes(5);
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn(WriteResult::Existing)->shouldBeCalledTimes(
            5,
        );
        $message = 'Cannot create a fragment in "/repo/.changelog": five generated identifiers already exist; no fragment was overwritten.';
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('five generated identifiers');
        $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
            new ReleaseOptions('/repo'),
            'Body.',
        );
    }

    #[Test]
    public function writeFailureRetainsItsOriginalCauseAndExactCandidatePath(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(
            new ChangesetParseResult('entry.md', $fragment, []),
        )->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $original = new RuntimeException('disk unavailable');
        $store->write('/repo/.changelog/entry.md', 'canonical')->willThrow($original)->shouldBeCalledOnce();
        $message = 'Cannot create fragment "/repo/.changelog/entry.md": disk unavailable';
        try {
            $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
                new ReleaseOptions('/repo'),
                'Body.',
                name: 'entry.md',
            );
            self::fail('The write failure must remain observable.');
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertSame(0, $error->getCode());
            self::assertSame($original, $error->getPrevious());
        }
    }

    #[Test]
    public function failedCommitPreservesTheCreatedFragmentAndReportsRecoveryState(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(
            new ChangesetParseResult('entry.md', $fragment, []),
        )->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn(WriteResult::Created)->shouldBeCalledOnce();
        $store->remove(Argument::any())->shouldNotBeCalled();
        $original = new RuntimeException('commit rejected');
        $git->commitFragment('/repo', '.changelog/entry.md', 'chore: record changelog fragment')->willThrow(
            $original,
        )->shouldBeCalledOnce();
        $message = 'Created fragment "/repo/.changelog/entry.md", but its commit failed: commit rejected. The fragment is preserved for recovery.';
        try {
            $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
                new ReleaseOptions('/repo'),
                'Body.',
                name: 'entry.md',
                commit: true,
            );
            self::fail('The commit failure must retain the fragment recovery diagnostic.');
        } catch (RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
            self::assertSame(0, $error->getCode());
            self::assertSame($original, $error->getPrevious());
        }
    }

    #[Test]
    public function emptyCommitMessageIsRejectedBeforeWriting(): void
    {
        [$parser, $renderer, $store, $identifiers, $git] = $this->collaborators();
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->writer($parser, $renderer, $store, $identifiers, $git)->add(
            new ReleaseOptions('/repo'),
            'Body.',
            commit: true,
            commitMessage: ' ',
        );
    }

    /** Builds fresh doubles for every side-effect or schema boundary. */
    private function collaborators(): array
    {
        return [
            $this->prophesize(ChangesetParserInterface::class),
            $this->prophesize(ChangesetRendererInterface::class),
            $this->prophesize(ChangesetStoreInterface::class),
            $this->prophesize(IdentifierGeneratorInterface::class),
            $this->prophesize(GitRepositoryInterface::class),
        ];
    }

    /** Composes the real authoring service without requesting any external state. */
    private function writer(
        ObjectProphecy $parser,
        ObjectProphecy $renderer,
        ObjectProphecy $store,
        ObjectProphecy $identifiers,
        ObjectProphecy $git,
    ): FragmentWriter {
        return new FragmentWriter(
            $parser->reveal(),
            $renderer->reveal(),
            $store->reveal(),
            $identifiers->reveal(),
            $git->reveal(),
        );
    }
}
