<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Automation\Dependabot\Factory;

use FastForward\Changelog\Automation\Dependabot\DependabotInput;
use FastForward\Changelog\Automation\Dependabot\Factory\DependabotInputFactory;
use FastForward\Changelog\Automation\Policy\GitHubEvidence;
use FastForward\Changelog\GitHub\Factory\GitHubExceptionFactoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(DependabotInputFactory::class)]
#[CoversClass(DependabotInput::class)]
#[UsesClass(GitHubEvidence::class)]
final class DependabotInputFactoryTest extends TestCase
{
    #[Test]
    public function normalizesTrustedMetadataWithInclusiveDefaults(): void
    {
        $value = $this->factory()->create(
            7,
            str_repeat('a', 40),
            ['z/package', 'a/package', 'z/package'],
            'direct:production',
            'composer',
            [9, 2, 9],
        );
        self::assertSame(['a/package', 'z/package'], $value->packageNames);
        self::assertSame([2, 9], $value->securityAlertNumbers);
        self::assertSame(7, $value->pullRequest);
        self::assertSame(str_repeat('a', 40), $value->expectedHeadSha);
        self::assertSame('direct:production', $value->dependencyType);
        self::assertSame('composer', $value->ecosystem);
        self::assertTrue($value->includeDev);
        self::assertTrue($value->includeActions);
        $filtered = $this->factory()->create(
            8,
            str_repeat('a', 40),
            ['actions/checkout'],
            'direct:development',
            'github-actions',
            [],
            false,
            false,
        );
        self::assertFalse($filtered->includeDev);
        self::assertFalse($filtered->includeActions);
    }

    #[Test]
    #[TestWith([0, 'sha', [], 'unknown', 'Composer', []])]
    #[TestWith([7, 'short', ['a/b'], 'indirect', 'composer', []])]
    #[TestWith([7, 'valid', [], 'indirect', 'composer', []])]
    #[TestWith([7, 'valid', ['a/b'], 'unknown', 'composer', []])]
    #[TestWith([7, 'valid', ['a/b'], 'indirect', 'composer?token=x', []])]
    #[TestWith([7, 'valid', ['bad`body'], 'indirect', 'composer', []])]
    #[TestWith([7, 'valid', ['../body'], 'indirect', 'composer', []])]
    #[TestWith([7, 'valid', [3], 'indirect', 'composer', []])]
    #[TestWith([7, 'valid', ['a/b'], 'indirect', 'composer', [0]])]
    #[TestWith([7, 'valid', ['a/b'], 'indirect', 'composer', ['4']])]
    public function invalidMetadataFailsBeforeAnyExternalEffect(
        int $number,
        string $sha,
        array $packages,
        string $type,
        string $ecosystem,
        array $alerts,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->factory()->create(
            $number,
            'valid' === $sha ? str_repeat('a', 40) : $sha,
            $packages,
            $type,
            $ecosystem,
            $alerts,
        );
    }

    private function factory(): DependabotInputFactory
    {
        $errors = $this->createStub(GitHubExceptionFactoryInterface::class);
        $errors->method('failure')->willReturnCallback(
            static fn(string $message): RuntimeException => new RuntimeException($message),
        );

        return new DependabotInputFactory($errors);
    }
}
