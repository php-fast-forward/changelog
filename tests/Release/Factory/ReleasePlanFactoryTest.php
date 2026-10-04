<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Release\Factory;

use FastForward\Changelog\Release\Factory\ReleasePlanFactory;
use FastForward\Changelog\Release\ReceiptCodecInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Release\ReleasePlan;
use FastForward\Changelog\Release\ReleaseReceipt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ReleasePlanFactory::class)]
#[CoversClass(ReleasePlan::class)]
#[CoversClass(ReleaseOptions::class)]
#[UsesClass(ReleaseReceipt::class)]
final class ReleasePlanFactoryTest extends TestCase
{
    /** The factory MUST supply canonical input evidence while delegating receipt identity to its codec. */
    public function testReleasePlanDelegatesIdentityAndUsesRelativeReceiptPaths(): void
    {
        $options = new ReleaseOptions('/consumer');
        $codec = $this->createMock(ReceiptCodecInterface::class);
        $changes = ['/consumer/.changelog/b.md' => hash('sha256', 'b'), '/consumer/.changelog/a.md' => hash('sha256', 'a')];
        $captured = [];
        $codec->expects(self::exactly(2))->method('encode')->willReturnCallback(static function (array $evidence) use (&$captured): string {
            $captured[] = $evidence;
            return 'canonical receipt';
        });
        $codec->expects(self::exactly(2))->method('decode')->with('canonical receipt')->willReturn(new ReleaseReceipt(['id' => 'approved-id']));
        $factory = new ReleasePlanFactory($codec);
        $base = str_repeat('a', 40);
        $plan = $factory->create($options, $base, '1.0.0+build.001', '1.0.1', 'patch', $changes, ['0.1.0+legacy.docs'], '/consumer/CHANGELOG.md', 'before', 'after', "Exact notes\n", '/consumer/.git/changelog-release-plan.json', null);
        $again = $factory->create($options, $base, '1.0.0+build.001', '1.0.1', 'patch', array_reverse($changes, true), ['0.1.0+legacy.docs'], '/consumer/CHANGELOG.md', 'before', 'after', "Exact notes\n", '/consumer/.git/changelog-release-plan.json', null);
        self::assertSame($captured[0], $captured[1]);
        self::assertSame($base, $captured[0]['base_sha']);
        self::assertSame('1.0.0+build.001', $captured[0]['current_version']);
        self::assertSame(['0.1.0+legacy.docs'], $captured[0]['historical_versions']);
        self::assertSame(['.changelog/a.md', '.changelog/b.md'], array_keys($captured[0]['consumed']));
        self::assertSame("Exact notes\n", $captured[0]['notes']);
        self::assertSame(hash('sha256', "Exact notes\n"), $captured[0]['notes_sha256']);
        self::assertSame(hash('sha256', 'before'), $captured[0]['before_changelog_sha256']);
        self::assertSame('after', $captured[0]['changelog_contents']);
        self::assertSame(hash('sha256', 'after'), $captured[0]['after_changelog_sha256']);
        self::assertSame('approved-id', $plan->id);
        self::assertSame($plan->id, $again->id);
        self::assertSame('release', $plan->mode());
        self::assertSame(['/consumer/CHANGELOG.md', '/consumer/.changelog/a.md', '/consumer/.changelog/b.md'], $plan->affectedFiles());
        self::assertSame($plan->id, $plan->summary()['id']);
        self::assertSame('release', $plan->summary()['mode']);
        self::assertFalse($plan->summary()['resuming']);
        self::assertSame("Exact notes\n", $plan->summary()['notes']);
    }

    /** Maintenance and empty plans MUST retain null-before evidence and explicit mode. */
    public function testMaintenanceAndNoChangeStatesAreExplicit(): void
    {
        $codec = $this->createStub(ReceiptCodecInterface::class);
        $codec->method('encode')->willReturnCallback(static function (array $evidence): string {
            self::assertNull($evidence['before_changelog_sha256']);
            return 'receipt';
        });
        $codec->method('decode')->willReturn(new ReleaseReceipt(['id' => 'id']));
        $factory = new ReleasePlanFactory($codec);
        $options = new ReleaseOptions('/consumer');
        $maintenance = $factory->create($options, null, '0.0.0', null, null, [], ['0.1.0'], '/consumer/CHANGELOG.md', null, 'Imported history', '', '/consumer/.git/changelog-release-plan.json', null);
        self::assertSame('maintenance', $maintenance->mode());
        $empty = $factory->create($options, null, '0.0.0', null, null, [], [], '/consumer/CHANGELOG.md', null, '', '', '/consumer/.git/changelog-release-plan.json', null);
        self::assertSame('none', $empty->mode());
        self::assertSame([], $empty->affectedFiles());
        $unchanged = new ReleasePlan($options, 'id', null, '0.0.0', null, null, [], [], '/consumer/CHANGELOG.md', 'same', 'same', '', '/consumer/.git/changelog-release-plan.json', null, 'receipt');
        self::assertSame('none', $unchanged->mode());
    }

    /** Resume MUST preserve original identity, raw receipt and the complete approved consumed set. */
    public function testResumeRestoresCompleteReceiptEvidenceWithoutRecoding(): void
    {
        $options = new ReleaseOptions('/consumer');
        $codec = $this->createMock(ReceiptCodecInterface::class);
        $codec->expects(self::never())->method('encode');
        $codec->expects(self::never())->method('decode');
        $data = ['id' => 'approved-id', 'base_sha' => str_repeat('a', 40), 'current_version' => '1.0.0', 'next_version' => '1.0.1',
            'impact' => 'patch', 'consumed' => ['.changelog/a.md' => hash('sha256', 'a')], 'historical_versions' => ['0.1.0'], 'changelog_contents' => 'after', 'notes' => "Exact notes\n"];
        $plan = new ReleasePlanFactory($codec)->resume($options, new ReleaseReceipt($data), '/consumer/CHANGELOG.md', 'after', '/consumer/.git/changelog-release-plan.json', "original receipt\n");
        self::assertSame('approved-id', $plan->id);
        self::assertSame(['/consumer/.changelog/a.md' => hash('sha256', 'a')], $plan->consumed);
        self::assertSame("original receipt\n", $plan->originalReceipt);
        self::assertSame($plan->originalReceipt, $plan->receiptContents);
        self::assertSame('after', $plan->originalChangelog);
        self::assertSame('after', $plan->changelogContents);
        self::assertSame("Exact notes\n", $plan->notes);
        self::assertTrue($plan->resuming);
        self::assertSame('release', $plan->mode());
        $data['next_version'] = null;
        $data['impact'] = null;
        $maintenance = new ReleasePlanFactory($codec)->resume($options, new ReleaseReceipt($data), '/consumer/CHANGELOG.md', 'after', '/consumer/.git/changelog-release-plan.json', 'receipt');
        self::assertSame('maintenance', $maintenance->mode());
        $prepared = new ReleasePlanFactory($codec)->resume($options, new ReleaseReceipt($data), '/consumer/CHANGELOG.md', null, '/consumer/.git/changelog-release-plan.json', 'receipt');
        self::assertNull($prepared->originalChangelog);
        self::assertSame('after', $prepared->changelogContents);
        self::assertSame('receipt', $prepared->receiptContents);
    }
}
