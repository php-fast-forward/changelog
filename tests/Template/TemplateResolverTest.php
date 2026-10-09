<?php

declare(strict_types=1);

namespace FastForward\Changelog\Tests\Template;

use FastForward\Changelog\Configuration\Factory\ConfigSourceFactoryInterface;
use FastForward\Changelog\Filesystem\ManagedFileStoreInterface;
use FastForward\Changelog\Filesystem\PackagePathResolverInterface;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;
use FastForward\Changelog\Release\ReleaseOptions;
use FastForward\Changelog\Template\Factory\TemplateFactoryInterface;
use FastForward\Changelog\Template\TemplateInterface;
use FastForward\Changelog\Template\TemplateResolver;
use FastForward\Config\ConfigInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TemplateResolver::class)]
#[UsesClass(ReleaseOptions::class)]
final class TemplateResolverTest extends TestCase
{
    /** Built-ins resolve locale without consulting a project file or executing PHP. */
    public function testBuiltinRequiresNoFileAccess(): void
    {
        $templates = $this->createMock(TemplateFactoryInterface::class);
        $template = $this->createStub(TemplateInterface::class);
        $templates->expects(self::once())->method('create')->with('pt-BR')->willReturn($template);
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->expects(self::never())->method('read');
        $sources = $this->createMock(ConfigSourceFactoryInterface::class);
        $sources->expects(self::never())->method('create');
        $resolver = new TemplateResolver($templates, $this->createStub(
            PackagePathResolverInterface::class,
        ), $files, $sources, $this->createStub(
            ReleaseExceptionFactoryInterface::class,
        ));
        self::assertSame($template, $resolver->resolve(new ReleaseOptions('/consumer', locale: 'pt-BR')));
    }

    /** Explicit custom presentation uses the guarded consumer path and lazy config boundary. */
    public function testCustomOverridesUseTheCheckedConsumerFile(): void
    {
        $templates = $this->createMock(TemplateFactoryInterface::class);
        $template = $this->createStub(TemplateInterface::class);
        $templates->expects(self::once())->method('create')->with('en', ['introduction' => 'Custom'])->willReturn(
            $template,
        );
        $paths = $this->createMock(PackagePathResolverInterface::class);
        $paths->expects(self::once())->method('absolutePath')->with('presentation.php', '/consumer')->willReturn(
            '/consumer/presentation.php',
        );
        $files = $this->createMock(ManagedFileStoreInterface::class);
        $files->expects(self::once())->method('read')->with('/consumer/presentation.php')->willReturn(
            'Checked PHP bytes',
        );
        $config = $this->createMock(ConfigInterface::class);
        $config->expects(self::once())->method('toArray')->willReturn(['introduction' => 'Custom']);
        $sources = $this->createMock(ConfigSourceFactoryInterface::class);
        $sources->expects(self::once())->method('create')->with('/consumer/presentation.php')->willReturn($config);
        self::assertSame(
            $template,
            new TemplateResolver($templates, $paths, $files, $sources, $this->createStub(
                ReleaseExceptionFactoryInterface::class,
            ))->resolve(new ReleaseOptions(
                '/consumer',
                template: 'presentation.php',
            )),
        );
    }

    /** Missing custom files produce a diagnostic before the PHP execution boundary. */
    public function testMissingCustomTemplateCannotExecute(): void
    {
        $templates = $this->createMock(TemplateFactoryInterface::class);
        $templates->expects(self::never())->method('create');
        $paths = $this->createStub(PackagePathResolverInterface::class);
        $paths->method('absolutePath')->willReturn('/consumer/missing.php');
        $files = $this->createStub(ManagedFileStoreInterface::class);
        $files->method('read')->willReturn(null);
        $sources = $this->createMock(ConfigSourceFactoryInterface::class);
        $sources->expects(self::never())->method('create');
        $exceptions = $this->createStub(ReleaseExceptionFactoryInterface::class);
        $exceptions->method('invalid')->willReturnCallback(
            static fn(string $message): InvalidArgumentException => new InvalidArgumentException($message),
        );
        $this->expectExceptionMessage('The selected PHP template does not exist: missing.php');
        new TemplateResolver($templates, $paths, $files, $sources, $exceptions)->resolve(
            new ReleaseOptions('/consumer', template: 'missing.php'),
        );
    }
}
