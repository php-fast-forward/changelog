<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Version;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\Changeset;
use FastForward\Changelog\Changeset\VersionImpact;
use FastForward\Changelog\Version\Factory\VersionResolutionFactoryInterface;
use FastForward\Changelog\Version\NextVersionResolver;
use FastForward\Changelog\Version\VersionImpactResolverInterface;
use FastForward\Changelog\Version\VersionResolution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(NextVersionResolver::class)]
#[UsesClass(Changeset::class)]
#[UsesClass(Category::class)]
#[UsesClass(VersionResolution::class)]
final class NextVersionResolverTest extends TestCase
{
    use ProphecyTrait;

    #[Test]
    #[TestWith(['1.2.3+build.7', VersionImpact::Patch, '1.2.4'])]
    #[TestWith(['v1.2.3', VersionImpact::Minor, '1.3.0'])]
    #[TestWith(['V1.2.3+release.9', VersionImpact::Major, '2.0.0'])]
    #[TestWith(['99999999999999999999.2.3', VersionImpact::Major, '100000000000000000000.0.0'])]
    #[TestWith(['1.99999999999999999999.3', VersionImpact::Minor, '1.100000000000000000000.0'])]
    #[TestWith(['1.2.99999999999999999999', VersionImpact::Patch, '1.2.100000000000000000000'])]
    public function resolveAppliesEachImpactToTheNumericCore(
        string $currentVersion,
        VersionImpact $impact,
        string $nextVersion,
    ): void {
        $changeset = new Changeset('one.md', Category::Fixed, null, 10, '@bot', 'Change.');
        $changesets = [$changeset];
        $impactResolver = $this->prophesize(VersionImpactResolverInterface::class);
        $resultFactory = $this->prophesize(VersionResolutionFactoryInterface::class);
        $expected = new VersionResolution($nextVersion, $impact, []);
        $impactResolver->resolve($changesets)->willReturn($impact)->shouldBeCalledOnce();
        $resultFactory->resolved($nextVersion, $impact)->willReturn($expected)->shouldBeCalledOnce();
        $resolver = new NextVersionResolver($impactResolver->reveal(), $resultFactory->reveal());

        self::assertSame($expected, $resolver->resolve($currentVersion, $changesets));
    }

    #[Test]
    #[TestWith([''])]
    #[TestWith(['1.2'])]
    #[TestWith(['01.2.3'])]
    #[TestWith(['1.2.3.4'])]
    #[TestWith(['1.2.3-alpha.1'])]
    #[TestWith(['1.2.3-alpha..1'])]
    #[TestWith(['1.2.3-01'])]
    #[TestWith(['1.2.3+.'])]
    #[TestWith(["1.2.3\n"])]
    public function resolveRejectsUnsupportedSemanticVersionsBeforeAggregating(string $currentVersion): void
    {
        $changeset = new Changeset('one.md', Category::Fixed, null, 10, '@bot', 'Change.');
        $changesets = [$changeset];
        $impactResolver = $this->prophesize(VersionImpactResolverInterface::class);
        $resultFactory = $this->prophesize(VersionResolutionFactoryInterface::class);
        $errors = [sprintf('Current version "%s" is not a supported semantic version.', $currentVersion)];
        $expected = new VersionResolution(null, null, $errors);
        $impactResolver->resolve($changesets)->shouldNotBeCalled();
        $resultFactory->invalid($errors)->willReturn($expected)->shouldBeCalledOnce();
        $resolver = new NextVersionResolver($impactResolver->reveal(), $resultFactory->reveal());

        self::assertSame($expected, $resolver->resolve($currentVersion, $changesets));
    }

    #[Test]
    public function resolveRejectsAValidCurrentVersionWithoutChangesets(): void
    {
        $impactResolver = $this->prophesize(VersionImpactResolverInterface::class);
        $resultFactory = $this->prophesize(VersionResolutionFactoryInterface::class);
        $errors = ['At least one changeset is required to resolve a version.'];
        $expected = new VersionResolution(null, null, $errors);
        $impactResolver->resolve([])->willReturn(null)->shouldBeCalledOnce();
        $resultFactory->invalid($errors)->willReturn($expected)->shouldBeCalledOnce();
        $resolver = new NextVersionResolver($impactResolver->reveal(), $resultFactory->reveal());

        self::assertSame($expected, $resolver->resolve('0.0.0', []));
    }
}
