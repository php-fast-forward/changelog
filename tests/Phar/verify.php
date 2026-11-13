<?php

declare(strict_types=1);

/** Disposable PHAR CLI evidence; never publishes or writes to a real consumer. */
$source = dirname(__DIR__, 2);
require $source . '/vendor/autoload.php';
$artifact = realpath($argv[1] ?? $source . '/dist/changelog');
if (false === $artifact) {
    throw new RuntimeException('Build dist/changelog with Box first.');
}
$filesystem = new Symfony\Component\Filesystem\Filesystem();
$temporary = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir()) . '/changelog-phar-fixture-' . bin2hex(
    random_bytes(8),
);
$filesystem->mkdir($temporary, 0700);
$environment = [
    'GIT_DIR' => false, 'GIT_WORK_TREE' => false, 'GIT_INDEX_FILE' => false,
    'GIT_COMMON_DIR' => false, 'GIT_OBJECT_DIRECTORY' => false,
    'GIT_ALTERNATE_OBJECT_DIRECTORIES' => false, 'GIT_CONFIG_COUNT' => false,
    'GIT_CONFIG_PARAMETERS' => false, 'GIT_CONFIG' => false,
    'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null',
    'FF_CHANGELOG_TOKEN' => false, 'GITHUB_TOKEN' => false, 'GH_TOKEN' => false,
];
$assertions = 0;
$verify = static function (bool $condition, string $message) use (&$assertions): void {
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$run = static function (array $arguments, int $exit = 0) use ($artifact, $temporary, $verify, $environment): string {
    $process = new Symfony\Component\Process\Process(
        [PHP_BINARY, '-d', 'memory_limit=128M', $artifact, ...$arguments],
        $temporary,
        $environment,
    );
    $process->run();
    $verify($process->getExitCode() === $exit, $process->getErrorOutput());

    return $process->getOutput();
};
try {
    Phar::loadPhar($artifact, 'changelog-inspection.phar');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $source . '/resources',
        FilesystemIterator::SKIP_DOTS,
    )) as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
        $bundled = 'phar://changelog-inspection.phar/' . $relative;
        $verify(is_file($bundled), 'Bundled resource is absent: ' . $relative);
        $expected = file_get_contents($file->getPathname());
        $actual = file_get_contents($bundled);
        if ($expected !== $actual && str_replace("\r\n", "\n", $expected) === $actual) {
            $canonical = new Symfony\Component\Process\Process(
                ['git', '-C', $source, 'show', 'HEAD:' . $relative],
                env: $environment,
            );
            $canonical->mustRun();
            $expected = $canonical->getOutput();
        }
        $verify($expected === $actual, 'Bundled resource differs: ' . $relative
            . '; checkout=' . hash('sha256', $expected) . '/' . strlen($expected)
            . '; phar=' . hash('sha256', $actual) . '/' . strlen($actual)
            . '; crlf-only=' . (str_replace("\r\n", "\n", $expected) === $actual ? 'true' : 'false'));
    }
    foreach (['LICENSE', 'composer.json', 'composer.lock', 'vendor/autoload.php'] as $file) {
        $verify(is_file('phar://changelog-inspection.phar/' . $file), 'Required runtime file missing: ' . $file);
    }
    $verify(!is_dir('phar://changelog-inspection.phar/vendor/phpunit'), 'Development tooling must not be bundled.');
    $verify(str_contains($run(['list', '--raw']), 'skill:install'), 'Standalone command list unavailable.');
    $run(['not-a-command'], 1);
    $baseline = glob(dirname($temporary) . '/fast-forward-changelog-*');
    $preview = json_decode($run(['skill:install', '--dry-run', '--json']), true, 512, JSON_THROW_ON_ERROR);
    $verify($preview['action'] === 'install' && !is_dir(
        $temporary . '/.agents',
    ), 'Skill dry run must not mutate the project.');
    $installed = json_decode($run(['skill:install', '--json']), true, 512, JSON_THROW_ON_ERROR);
    $verify($installed['state'] === 'installed', 'Bundled native skill did not install.');
    $verify(file_get_contents($temporary . '/.agents/skills/fast-forward-changelog/SKILL.md') === file_get_contents(
        'phar://changelog-inspection.phar/resources/skills/fast-forward-changelog/SKILL.md',
    ), 'Installed skill bytes differ.');
    $run(['add', 'PHAR fixture change.', '--category=fixed', '--no-interaction']);
    $run(['check', '--no-interaction']);
    file_put_contents(
        $temporary . '/TemplateText.php',
        '<?php namespace Fixture; final class TemplateText { public static function render(): string { return "PHAR callback template."; } }',
    );
    file_put_contents(
        $temporary . '/template.php',
        '<?php spl_autoload_register(static function ($class): void { if ($class === "Fixture\\\\TemplateText") { require __DIR__ . "/TemplateText.php"; } }); $text = Fixture\\TemplateText::render(); file_put_contents(__DIR__ . "/template-loaded", $text); return ["introduction" => $text];',
    );
    $process = new Symfony\Component\Process\Process(['git', 'init', '-q', $temporary], env: $environment);
    $process->mustRun();
    (new Symfony\Component\Process\Process(['git', '-C', $temporary, 'add', '.'], env: $environment))->mustRun();
    (new Symfony\Component\Process\Process([
        'git', '-C', $temporary, '-c', 'commit.gpgsign=false', '-c', 'user.name=Fixture',
        '-c', 'user.email=fixture@example.invalid', '-c', 'core.hooksPath=' . $temporary . '/no-hooks',
        'commit', '-qm', 'Disposable fixture',
    ], env: $environment))->mustRun();
    $run([
        'version',
        '--dry-run',
        '--source=tags',
        '--repository=example/fixture',
        '--template=template.php',
        '--no-interaction',
    ]);
    $verify(file_get_contents(
        $temporary . '/template-loaded',
    ) === 'PHAR callback template.', 'Explicit project PHP template and its autoload callback were not executed.');
    $verify(!file_exists($temporary . '/CHANGELOG.md'), 'Version dry run wrote history.');
    $verify($baseline === glob(
        dirname($temporary) . '/fast-forward-changelog-*',
    ), 'PHAR invocation left an extracted runtime behind.');
    echo 'PHAR PASS: ', $assertions, " assertions; bundled resources, real skill copy, fragments, explicit PHP template, Git, exits and shutdown cleanup.\n";
} finally {
    $filesystem->chmod($temporary, 0700, 0000, true);
    $filesystem->remove($temporary);
}
