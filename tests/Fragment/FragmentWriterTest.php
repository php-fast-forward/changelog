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
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Fragment\Factory\FragmentExceptionFactoryInterface;
use FastForward\Changelog\Fragment\FragmentWriter;
use FastForward\Changelog\Fragment\IdentifierGeneratorInterface;
use FastForward\Changelog\Git\GitRepositoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
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
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $path = '/repo/.changelog/dependency.md';
        $body = "    preserved  \nsecond  ";
        $fragment = new Changeset('dependency.md', Category::Changed, 1, 2, 'coisa', $body, VersionImpact::Patch);
        $input = "---\ncategory: changed\ntype: patch\nissue: 1\npull_request: 2\nauthor: \"@coisa\"\n---\n\n{$body}";
        $parser->parse($path, $input)->willReturn(new ChangesetParseResult('dependency.md', $fragment, []))->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical markdown')->shouldBeCalledOnce();
        $store->write($path, 'canonical markdown')->willReturn(WriteResult::Created)->shouldBeCalledOnce();
        $identifiers->generate()->shouldNotBeCalled();
        $git->commitFragment(Argument::any(), Argument::any(), Argument::any())->shouldNotBeCalled();

        self::assertSame($path, $this->writer(...[$parser, $renderer, $store, $identifiers, $git, $exceptions])->add(new ReleaseOptions('/repo'), $body, type: 'patch', name: 'dependency.md', issue: 1, pullRequest: 2, author: '@coisa'));
    }

    #[Test]
    public function generatedCollisionsRetryAndAnOptionalCommitReceivesOnlyTheCreatedRelativePath(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $identifiers->generate()->willReturn('first.md', 'second.md')->shouldBeCalledTimes(2);
        foreach (['first.md' => WriteResult::Existing, 'second.md' => WriteResult::Created] as $id => $result) {
            $fragment = new Changeset($id, Category::Deprecated, null, null, null, 'Body.');
            $parser->parse('/repo/.changelog/' . $id, "---\ncategory: deprecated\ntype: minor\n---\n\nBody.")->willReturn(new ChangesetParseResult($id, $fragment, []))->shouldBeCalledOnce();
            $renderer->render($fragment)->willReturn('canonical ' . $id)->shouldBeCalledOnce();
            $store->write('/repo/.changelog/' . $id, 'canonical ' . $id)->willReturn($result)->shouldBeCalledOnce();
        }
        $git->commitFragment('/repo', '.changelog/second.md', 'record fragment only')->willReturn('sha')->shouldBeCalledOnce();

        self::assertSame('/repo/.changelog/second.md', $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.', 'deprecated', commit: true, commitMessage: 'record fragment only'));
    }

    #[Test]
    #[TestWith([WriteResult::Existing])]
    #[TestWith([WriteResult::UnsafePath])]
    #[TestWith([WriteResult::LockUnavailable])]
    #[TestWith([WriteResult::Available])]
    public function explicitIdentifiersNeverRetryOrOverwriteWhenCreationCannotComplete(WriteResult $result): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(new ChangesetParseResult('entry.md', $fragment, []))->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn($result)->shouldBeCalledOnce();
        $identifiers->generate()->shouldNotBeCalled();
        $message = sprintf('Cannot create fragment "/repo/.changelog/entry.md": %s.', $result->value);
        $exceptions->failure($message)->willReturn(new RuntimeException($message))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($result->value);
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.', name: 'entry.md');
    }

    #[Test]
    public function aGeneratedNameCannotEscapeTheFragmentDirectory(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $identifiers->generate()->willReturn('../outside.md')->shouldBeCalledOnce();
        $message = 'Invalid fragment name "../outside.md": use lowercase dash segments and .md.';
        $exceptions->invalid($message)->willReturn(new InvalidArgumentException($message))->shouldBeCalledOnce();
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.');
    }

    #[Test]
    public function schemaFailureIncludesAllDiagnosticsBeforeWriting(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $parser->parse('/repo/.changelog/entry.md', "---\ncategory: invalid\ntype: minor\n---\n\n")->willReturn(new ChangesetParseResult('entry.md', null, ['bad category', 'empty body']))->shouldBeCalledOnce();
        $message = 'Invalid fragment "/repo/.changelog/entry.md": bad category empty body';
        $exceptions->invalid($message)->willReturn(new InvalidArgumentException($message))->shouldBeCalledOnce();
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad category empty body');
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), '', 'invalid', name: 'entry.md');
    }

    #[Test]
    public function fiveGeneratedCollisionsFailWithoutReplacingAnyFile(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $identifiers->generate()->willReturn('entry.md')->shouldBeCalledTimes(5);
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(new ChangesetParseResult('entry.md', $fragment, []))->shouldBeCalledTimes(5);
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledTimes(5);
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn(WriteResult::Existing)->shouldBeCalledTimes(5);
        $message = 'Cannot create a fragment in "/repo/.changelog": five generated identifiers already exist; no fragment was overwritten.';
        $exceptions->failure($message)->willReturn(new RuntimeException($message))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('five generated identifiers');
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.');
    }

    #[Test]
    public function writeFailureRetainsItsOriginalCauseAndExactCandidatePath(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(new ChangesetParseResult('entry.md', $fragment, []))->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $original = new RuntimeException('disk unavailable');
        $store->write('/repo/.changelog/entry.md', 'canonical')->willThrow($original)->shouldBeCalledOnce();
        $message = 'Cannot create fragment "/repo/.changelog/entry.md": disk unavailable';
        $exceptions->failure($message, $original)->willReturn(new RuntimeException($message, previous: $original))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('/repo/.changelog/entry.md');
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.', name: 'entry.md');
    }

    #[Test]
    public function failedCommitPreservesTheCreatedFragmentAndReportsRecoveryState(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $fragment = new Changeset('entry.md', Category::Changed, null, null, null, 'Body.');
        $parser->parse('/repo/.changelog/entry.md', Argument::type('string'))->willReturn(new ChangesetParseResult('entry.md', $fragment, []))->shouldBeCalledOnce();
        $renderer->render($fragment)->willReturn('canonical')->shouldBeCalledOnce();
        $store->write('/repo/.changelog/entry.md', 'canonical')->willReturn(WriteResult::Created)->shouldBeCalledOnce();
        $store->remove(Argument::any())->shouldNotBeCalled();
        $original = new RuntimeException('commit rejected');
        $git->commitFragment('/repo', '.changelog/entry.md', 'chore: record changelog fragment')->willThrow($original)->shouldBeCalledOnce();
        $message = 'Created fragment "/repo/.changelog/entry.md", but its commit failed: commit rejected. The fragment is preserved for recovery.';
        $exceptions->failure($message, $original)->willReturn(new RuntimeException($message, previous: $original))->shouldBeCalledOnce();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fragment is preserved for recovery');
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.', name: 'entry.md', commit: true);
    }

    #[Test]
    public function emptyCommitMessageIsRejectedBeforeWriting(): void
    {
        [$parser, $renderer, $store, $identifiers, $git, $exceptions] = $this->collaborators();
        $exceptions->invalid('The fragment commit message must not be empty.')->willReturn(new InvalidArgumentException('commit message'))->shouldBeCalledOnce();
        $store->write(Argument::any(), Argument::any())->shouldNotBeCalled();
        $this->expectException(InvalidArgumentException::class);
        $this->writer($parser, $renderer, $store, $identifiers, $git, $exceptions)->add(new ReleaseOptions('/repo'), 'Body.', commit: true, commitMessage: ' ');
    }

    /** Builds fresh doubles for every side-effect or schema boundary. */
    private function collaborators(): array
    {
        return [$this->prophesize(ChangesetParserInterface::class), $this->prophesize(ChangesetRendererInterface::class), $this->prophesize(ChangesetStoreInterface::class), $this->prophesize(IdentifierGeneratorInterface::class), $this->prophesize(GitRepositoryInterface::class), $this->prophesize(FragmentExceptionFactoryInterface::class)];
    }

    /** Composes the real authoring service without requesting any external state. */
    private function writer(ObjectProphecy $parser, ObjectProphecy $renderer, ObjectProphecy $store, ObjectProphecy $identifiers, ObjectProphecy $git, ObjectProphecy $exceptions): FragmentWriter
    {
        return new FragmentWriter($parser->reveal(), $renderer->reveal(), $store->reveal(), $identifiers->reveal(), $git->reveal(), $exceptions->reveal());
    }
}
