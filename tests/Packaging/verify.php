<?php

declare(strict_types=1);

/**
 * Disposable Composer packaging and CLI integration evidence.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 */

$packageRoot = realpath($argv[1] ?? dirname(__DIR__, 2));
if (false === $packageRoot || !is_file($packageRoot . '/composer.json')) {
    fwrite(STDERR, "Supply the package checkout path.\n");
    exit(2);
}
$fixtureRoot = sys_get_temp_dir() . '/changelog-packaging-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700, true);
$cacheDirectory = $fixtureRoot . '/composer-cache';
if (isset($argv[2])) {
    $requested = realpath($argv[2]);
    $temporary = realpath(sys_get_temp_dir());
    if (false === $requested || false === $temporary || !str_starts_with($requested, $temporary . '/changelog-packaging-') || !str_ends_with($requested, '/composer-cache')) {
        throw new InvalidArgumentException('Only a previously generated disposable packaging cache may be reused.');
    }
    $cacheDirectory = $requested;
}
$assertions = 0;
$commands = 0;

/** Fails with an exact fixture contract; the retained path supports diagnosis. */
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Runs structured arguments through RTK, capturing both streams without a pipe deadlock. */
function execute(array $arguments, string $directory, array $environment, int $expected = 0): array
{
    global $fixtureRoot, $commands;
    ++$commands;
    $stdout = $fixtureRoot . '/command-' . $commands . '.out';
    $stderr = $fixtureRoot . '/command-' . $commands . '.err';
    $null = 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null';
    $process = proc_open(['rtk', 'proxy', ...$arguments], [0 => ['file', $null, 'r'], 1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, $directory, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch fixture command.');
    }
    $exit = proc_close($process);
    $result = ['exit' => $exit, 'stdout' => file_get_contents($stdout), 'stderr' => file_get_contents($stderr)];
    if ($expected !== $exit) {
        throw new RuntimeException(sprintf("Command %s exited %d; expected %d.\n%s%s", implode(' ', $arguments), $exit, $expected, $result['stdout'], $result['stderr']));
    }
    return $result;
}

/** Uses a process-specific HOME and ignores user Git/Composer credentials and configuration. */
function environment(string $fixtureRoot, string $composerHome): array
{
    global $cacheDirectory;
    $values = getenv();
    foreach (['GITHUB_TOKEN', 'GH_TOKEN', 'FF_CHANGELOG_INPUTS', 'COMPOSER', 'COMPOSER_AUTH', 'COMPOSER_BIN_DIR', 'COMPOSER_VENDOR_DIR', 'COMPOSER_ROOT_VERSION', 'COMPOSER_DISABLE_NETWORK', 'GIT_ASKPASS', 'SSH_ASKPASS', 'SSH_AUTH_SOCK', 'GIT_CONFIG_COUNT', 'GIT_CONFIG_PARAMETERS', 'GIT_CONFIG', 'GIT_SSH', 'GIT_SSH_COMMAND', 'GIT_DIR', 'GIT_WORK_TREE'] as $key) {
        unset($values[$key]);
    }
    return [...$values, 'HOME' => $fixtureRoot . '/home', 'COMPOSER_HOME' => $composerHome,
        'COMPOSER_CACHE_DIR' => $cacheDirectory, 'COMPOSER_AUTH' => '{}',
        'COMPOSER_NO_INTERACTION' => '1', 'COMPOSER_DISABLE_XDEBUG_WARN' => '1', 'XDEBUG_MODE' => 'off',
        'XDG_CONFIG_HOME' => $fixtureRoot . '/home/config', 'XDG_CACHE_HOME' => $fixtureRoot . '/home/cache',
        'TMPDIR' => $fixtureRoot . '/tmp', 'TEMP' => $fixtureRoot . '/tmp', 'TMP' => $fixtureRoot . '/tmp',
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => 'Windows' === PHP_OS_FAMILY ? 'NUL' : '/dev/null',
        'GITHUB_API_URL' => 'https://api.example.invalid', 'GIT_TERMINAL_PROMPT' => '0',
        'GIT_AUTHOR_NAME' => 'Packaging Fixture', 'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
        'GIT_COMMITTER_NAME' => 'Packaging Fixture', 'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        'GIT_AUTHOR_DATE' => '2026-01-01T00:00:00+00:00', 'GIT_COMMITTER_DATE' => '2026-01-01T00:00:00+00:00'];
}

/** Records consumer bytes while excluding disposable Git administrative state. */
function snapshot(string $directory): array
{
    $state = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
        if (str_starts_with($relative, '.git/')) {
            continue;
        }
        if ($file->isFile()) {
            $state[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($state);
    return $state;
}

/** Reads stable machine output and rejects progress or additional non-JSON text. */
function summary(array $result): array
{
    $value = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify(is_array($value) && isset($value['mode']), 'CLI summary must be one JSON object.');
    return $value;
}

/** Preserves the exact result of one installed executable invoked from its consumer cwd. */
function cli(string $binary, string $consumer, array $environment, array $arguments, int $expected = 0): array
{
    return execute([PHP_BINARY, $binary, ...$arguments, '--no-interaction', '--no-ansi'], $consumer, $environment, $expected);
}

/** Checks both installed runtime dependencies and the mirrored package bootstrap. */
function runtimeGraph(string $installation): void
{
    $data = json_decode(file_get_contents($installation . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
    $packages = $data['packages'] ?? $data;
    $names = array_column($packages, 'name');
    verify(in_array('fast-forward/changelog', $names, true), 'The product package was not installed.');
    foreach (['fast-forward/dev-tools', 'phpunit/phpunit', 'rector/rector', 'symplify/easy-coding-standard'] as $forbidden) {
        verify(!in_array($forbidden, $names, true), 'Development dependency leaked into the runtime graph: ' . $forbidden);
    }
    $package = $installation . '/vendor/fast-forward/changelog';
    verify(!is_link($package), 'The path repository must be mirrored with symlink=false.');
    verify(!is_dir($package . '/vendor'), 'The installed library must not bundle the checkout vendor directory.');
    fwrite(STDOUT, sprintf("Runtime graph: %d packages, no development tools.\n", count($packages)));
}

/** Exports current tracked/unignored source bytes without copying checkout vendor, caches or Git administration. */
function exportSource(string $packageRoot, string $destination, array $environment): void
{
    $files = execute(['git', 'ls-files', '--cached', '--others', '--exclude-standard', '-z'], $packageRoot, $environment);
    foreach (array_unique(explode("\0", $files['stdout'])) as $relative) {
        if ('' === $relative || !is_file($packageRoot . '/' . $relative)) {
            continue;
        }
        $target = $destination . '/' . $relative;
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0700, true);
        }
        if (!copy($packageRoot . '/' . $relative, $target)) {
            throw new RuntimeException('Cannot export package fixture file: ' . $relative);
        }
    }
    verify(!is_dir($destination . '/vendor'), 'Source export must exclude checkout dependencies.');
}

/** Exercises actual Composer proxies, selected-command construction and disposable Git transactions. */
function exercise(string $installation, string $consumer, array $environment): void
{
    $binary = $installation . '/vendor/bin/changelog';
    $installed = $installation . '/vendor/fast-forward/changelog';
    verify(is_file($binary), 'Composer did not expose vendor/bin/changelog.');
    if ('Windows' !== PHP_OS_FAMILY) {
        execute([$binary, 'list', '--raw', '--no-interaction', '--no-ansi'], $consumer, $environment);
    }
    // A development checkout may leave its own vendor tree in a path mirror.
    // The native Composer proxy must select its top-level installation autoloader.
    mkdir($installed . '/vendor', 0700, true);
    file_put_contents($installed . '/vendor/autoload.php', "<?php throw new RuntimeException('nested vendor must not win over native Composer');\n");
    try {
        cli($binary, $consumer, $environment, ['list', '--raw']);
    } finally {
        unlink($installed . '/vendor/autoload.php');
        rmdir($installed . '/vendor');
    }
    $listing = cli($binary, $consumer, $environment, ['list', '--raw']);
    foreach (['add', 'check', 'status', 'version', 'notes', 'publish', 'backfill', 'format'] as $command) {
        verify(1 === preg_match('/^' . $command . '\s/m', $listing['stdout']), 'Missing public command: ' . $command);
        verify(!str_contains($listing['stdout'], 'changelog:' . $command), 'A legacy prefixed alias leaked.');
    }
    verify(str_contains(cli($binary, $consumer, $environment, ['--version'])['stdout'], 'dev-main'), 'The binary did not use native installed package metadata.');
    cli($binary, $consumer, $environment, ['--help']);
    foreach (['add', 'check', 'status', 'version', 'notes', 'publish', 'backfill', 'format'] as $command) {
        cli($binary, $consumer, $environment, ['help', $command]);
    }

    $provider = $installed . '/src/Container/ServiceProvider/ChangelogServiceProvider.php';
    $originalProvider = file_get_contents($provider);
    $poisoned = str_replace("return [\n", "return [\n            \\FastForward\\Changelog\\Console\\Command\\StatusCommand::class => static fn(): never => throw new \\RuntimeException('fixture selected graph'),\n", $originalProvider, $replacements);
    verify(1 === $replacements, 'The lazy-loading probe could not identify the provider factory map.');
    file_put_contents($provider, $poisoned);
    try {
        cli($binary, $consumer, $environment, ['list', '--raw']);
        cli($binary, $consumer, $environment, ['--help']);
        $failure = cli($binary, $consumer, $environment, ['status', '--source=tags'], 1);
        verify(str_contains($failure['stdout'] . $failure['stderr'], 'fixture selected graph'), 'Selected-command failure did not retain its diagnostic.');
        cli($binary, $consumer, $environment, ['add', 'Graph failures stay isolated.', '--name=lazy-isolation.md']);
        unlink($consumer . '/.changelog/lazy-isolation.md');
    } finally {
        file_put_contents($provider, $originalProvider);
    }

    $poison = "<?php\nfile_put_contents(__DIR__ . '/executed-php-config', 'unexpected');\nthrow new RuntimeException('Implicit PHP configuration must not run.');\n";
    foreach (['changelog.php', '.changelog.php', '.ff-changelog.php', '.changelog/config.php'] as $name) {
        file_put_contents($consumer . '/' . $name, $poison);
    }
    $before = snapshot($consumer);
    cli($binary, $consumer, $environment, ['add', 'Preserve <info>literal</info> Markdown.']);
    $after = snapshot($consumer);
    $newFiles = array_diff_key($after, $before);
    verify(1 === count($newFiles), 'Default add must create exactly one consumer file.');
    $defaultPath = array_key_first($newFiles);
    verify(str_starts_with($defaultPath, '.changelog/') && str_ends_with($defaultPath, '.md'), 'Default add did not use the consumer fragment directory.');
    verify(!is_file($consumer . '/CHANGELOG.md') && !is_file($consumer . '/.changelog/release-plan.json'), 'Add wrote central history or a receipt.');
    verify([] === array_diff_assoc($before, $after), 'Add modified an existing consumer file.');
    verify(str_contains(file_get_contents($consumer . '/' . $defaultPath), '<info>literal</info>'), 'Authoring changed literal Markdown formatter tags.');
    verify(!is_file($installed . '/.changelog/' . basename($defaultPath)), 'Add used the package installation directory as cwd.');
    unlink($consumer . '/' . $defaultPath);
    cli($binary, $consumer, $environment, ['add'], 2);

    $git = static fn(array $arguments): array => execute(['git', ...$arguments], $consumer, $environment);
    $git(['init', '--initial-branch=main']);
    $git(['remote', 'add', 'origin', 'https://github.com/fixture/changelog.git']);
    $git(['config', 'user.name', 'Packaging Fixture']);
    $git(['config', 'user.email', 'fixture@example.invalid']);
    $git(['config', 'commit.gpgsign', 'false']);
    file_put_contents($consumer . '/README.md', "Disposable consumer.\n");
    file_put_contents($consumer . '/staged.txt', "baseline staged file\n");
    file_put_contents($consumer . '/working.txt', "baseline working file\n");
    file_put_contents($consumer . '/CHANGELOG.md', "# Changelog\n\n## [0.9.0] - 2025-12-01\n\n### Fixed\n\n- Prior fixture release.\n");
    $git(['add', '--all']);
    $git(['commit', '--message', 'test: prior release']);
    $git(['tag', 'v0.9.0']);
    $history = "# Changelog\n\nCustom fixture introduction.\n\n## [1.0.0] - 2026-01-01\n\n### Added\n\n- Baseline fixture release.\n\n[1.0.0]: https://example.invalid/releases/v1.0.0\n";
    file_put_contents($consumer . '/CHANGELOG.md', $history);
    $git(['add', '--', 'CHANGELOG.md']);
    $git(['commit', '--message', 'test: current release']);
    $git(['tag', 'v1.0.0']);
    $base = trim($git(['rev-parse', 'HEAD'])['stdout']);
    file_put_contents($consumer . '/staged.txt', "unrelated staged edit\n");
    file_put_contents($consumer . '/working.txt', "unrelated working edit\n");
    $git(['add', '--', 'staged.txt']);
    $index = $git(['diff', '--cached', '--binary'])['stdout'];
    cli($binary, $consumer, $environment, ['add', 'A deterministic <info>literal</info> contribution.', '--name=named.md']);
    $fragment = file_get_contents($consumer . '/.changelog/named.md');
    cli($binary, $consumer, $environment, ['add', 'Do not overwrite.', '--name=named.md'], 1);
    verify($fragment === file_get_contents($consumer . '/.changelog/named.md'), 'An explicit name collision overwrote a fragment.');
    verify($base === trim($git(['rev-parse', 'HEAD'])['stdout']), 'Default add unexpectedly committed.');
    cli($binary, $consumer, $environment, ['add', 'Only this fragment belongs in the commit.', '--category=fixed', '--type=patch', '--name=committed.md', '--commit', '--commit-message=test: scoped fragment']);
    verify(".changelog/committed.md\n" === $git(['show', '--pretty=format:', '--name-only', 'HEAD'])['stdout'], 'The optional commit included unrelated paths.');
    verify("test: scoped fragment\n" === $git(['log', '-1', '--format=%s'])['stdout'], 'The optional commit did not retain its explicit message.');
    verify($index === $git(['diff', '--cached', '--binary'])['stdout'], 'The optional commit changed unrelated staged work.');
    verify("unrelated working edit\n" === file_get_contents($consumer . '/working.txt'), 'The optional commit changed unrelated worktree bytes.');
    verify($history === file_get_contents($consumer . '/CHANGELOG.md'), 'Fragment creation changed the central history.');
    cli($binary, $consumer, $environment, ['check', '--since=' . $base]);

    $before = snapshot($consumer);
    $status = summary(cli($binary, $consumer, $environment, ['status', '--json', '--source=tags']));
    $preview = summary(cli($binary, $consumer, $environment, ['version', '--dry-run', '--source=tags']));
    verify('release' === $status['mode'] && '1.0.0' === $status['current_version'] && '1.1.0' === $status['next_version'], 'Status did not calculate the expected synthetic release.');
    foreach (['mode', 'current_version', 'next_version', 'impact', 'consumed', 'historical_versions', 'notes'] as $field) {
        verify($status[$field] === $preview[$field], 'Status and version preview disagree: ' . $field);
    }
    verify(['0.9.0'] === $preview['historical_versions'], 'The stable ancestor tag was not included as missing history.');
    verify(str_contains($preview['notes'], '<info>literal</info>'), 'Machine release notes changed literal Markdown formatter tags.');
    cli($binary, $consumer, $environment, ['version', '--check', '--source=tags'], 1);
    $backfill = summary(cli($binary, $consumer, $environment, ['backfill', '--dry-run', '--source=tags']));
    verify(null === $backfill['next_version'] && [] === $backfill['consumed'] && ['0.9.0'] === $backfill['historical_versions'], 'Backfill preview must import history without consuming pending fragments.');
    cli($binary, $consumer, $environment, ['backfill', '--check', '--source=tags'], 1);
    $format = summary(cli($binary, $consumer, $environment, ['format', '--dry-run', '--source=tags']));
    verify(null === $format['next_version'] && [] === $format['consumed'] && [] === $format['historical_versions'], 'Format preview must preserve the release inventory.');
    cli($binary, $consumer, $environment, ['format', '--check', '--source=tags']);
    cli($binary, $consumer, $environment, ['version', '--dry-run', '--check', '--source=tags'], 2);
    verify($before === snapshot($consumer), 'Preview, check or status mutated consumer bytes.');

    $uncommitted = snapshot($consumer);
    cli($binary, $consumer, $environment, ['version', '--source=tags'], 1);
    verify($uncommitted === snapshot($consumer), 'A fragment outside the approved Git base changed managed files before rejection.');
    verify($index === $git(['diff', '--cached', '--binary'])['stdout'], 'Rejected consolidation changed unrelated staged work.');
    $git(['add', '--', '.changelog/named.md']);
    $git(['commit', '--only', '--message', 'test: approve pending fragment', '--', '.changelog/named.md']);
    verify(".changelog/named.md\n" === $git(['show', '--pretty=format:', '--name-only', 'HEAD'])['stdout'], 'Approval committed unrelated paths.');
    verify($index === $git(['diff', '--cached', '--binary'])['stdout'], 'Fragment approval changed unrelated staged work.');
    $preview = summary(cli($binary, $consumer, $environment, ['version', '--dry-run', '--source=tags']));
    $applied = summary(cli($binary, $consumer, $environment, ['version', '--source=tags']));
    verify('1.1.0' === $applied['next_version'], 'The explicit fixture-only maintenance operation changed the approved version.');
    verify(!is_file($consumer . '/.changelog/named.md') && !is_file($consumer . '/.changelog/committed.md'), 'The local transaction did not consume its exact fragment set.');
    verify(!is_file($consumer . '/.changelog/release-plan.json'), 'Consolidation introduced a tracked plan file.');
    verify([] === glob($consumer . '/.git/changelog-release-plan*.json'), 'Successful Git consolidation retained a private journal.');
    verify(!str_contains(file_get_contents($consumer . '/CHANGELOG.md'), 'fast-forward-changelog:'), 'Consolidation polluted the history with generated metadata comments.');
    $changed = $git(['diff', '--name-status', '--', 'CHANGELOG.md', '.changelog'])['stdout'];
    verify("D\t.changelog/committed.md\nD\t.changelog/named.md\nM\tCHANGELOG.md\n" === $changed, 'The release diff must contain only history and consumed fragment deletions.');
    $notes = cli($binary, $consumer, $environment, ['notes', '1.1.0', '--source=tags']);
    verify($preview['notes'] === $notes['stdout'], 'Notes did not return the exact planned managed-history bytes.');
    verify($notes['stdout'] === cli($binary, $consumer, $environment, ['notes', '--source=tags'])['stdout'], 'Default notes did not select the latest maintained release.');
    cli($binary, $consumer, $environment, ['notes', '1.1.0', '--output=release-notes.md', '--source=tags']);
    verify($notes['stdout'] === file_get_contents($consumer . '/release-notes.md'), 'Managed notes output changed bytes.');
    $afterOutput = snapshot($consumer);
    cli($binary, $consumer, $environment, ['notes', '1.1.0', '--output=release-notes.md', '--source=tags'], 2);
    cli($binary, $consumer, $environment, ['notes', '1.1.0', '--output=CHANGELOG.md', '--source=tags'], 2);
    cli($binary, $consumer, $environment, ['notes', '1.1.0', '--output=.changelog/new-fragment.md', '--source=tags'], 2);
    verify($afterOutput === snapshot($consumer), 'Rejected notes output modified an existing or managed file.');
    verify($index === $git(['diff', '--cached', '--binary'])['stdout'], 'Local consolidation changed unrelated staged work.');
    verify("unrelated working edit\n" === file_get_contents($consumer . '/working.txt'), 'Local consolidation changed unrelated worktree bytes.');
    verify(is_string($applied['commit_message']), 'Git consolidation did not provide its reusable commit message.');
    $git(['commit', '--only', '--message', $applied['commit_message'], '--', 'CHANGELOG.md', '.changelog/committed.md', '.changelog/named.md']);
    verify($index === $git(['diff', '--cached', '--binary'])['stdout'], 'The consolidation commit changed unrelated staged work.');
    $approved = trim($git(['rev-parse', 'HEAD'])['stdout']);
    $proofCode = <<<'PHP'
require $argv[1];
$container = \FastForward\Container\container(new \FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider(workingDirectory: getcwd(), temporaryDirectory: $argv[3]));
$options = $container->get(\FastForward\Changelog\Release\Factory\ReleaseOptionsFactoryInterface::class)->create(['source' => 'tags']);
$evidence = $container->get(\FastForward\Changelog\Validator\PublicationEvidenceValidatorInterface::class)->validate($options, $argv[2]);
fwrite(STDOUT, json_encode(['version' => $evidence->version, 'tag' => $evidence->tag, 'notes' => $evidence->notes], JSON_THROW_ON_ERROR));
PHP;
    $proof = json_decode(execute([PHP_BINARY, '-r', $proofCode, $installation . '/vendor/autoload.php', $approved, realpath($environment['TMPDIR'])], $consumer, $environment)['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify('1.1.0' === $proof['version'] && 'v1.1.0' === $proof['tag'], 'Committed Git proof calculated the wrong publication identity.');
    verify($preview['notes'] === $proof['notes'], 'Committed publication proof changed the maintained release notes.');
    verify("v0.9.0\nv1.0.0\n" === $git(['tag', '--list'])['stdout'], 'Local CLI operations created a tag.');
    verify(!str_contains(implode('\n', array_keys(snapshot($consumer))), 'executed-php-config'), 'The default CLI executed implicit PHP configuration.');
    fwrite(STDOUT, "Consumer behavior PASS: {$consumer}\n");
}

/** Verifies that non-Git consolidation keeps recovery state outside the consumer and cleans it on success. */
function exerciseNonGit(string $installation, string $consumer, array $environment): void
{
    mkdir($consumer, 0700, true);
    $binary = $installation . '/vendor/bin/changelog';
    cli($binary, $consumer, $environment, ['add', 'Release output stays readable.', '--category=fixed', '--type=patch', '--name=plain-history.md']);
    $preview = summary(cli($binary, $consumer, $environment, ['version', '--dry-run', '--source=tags']));
    verify('0.0.1' === $preview['next_version'], 'Non-Git preview did not calculate its synthetic initial patch.');
    verify(!is_file($consumer . '/CHANGELOG.md'), 'Non-Git preview wrote central history.');
    $applied = summary(cli($binary, $consumer, $environment, ['version', '--source=tags']));
    verify($preview['next_version'] === $applied['next_version'], 'Non-Git application changed its previewed version.');
    $files = snapshot($consumer);
    verify(['CHANGELOG.md'] === array_keys($files), 'Non-Git consolidation left a technical file in the consumer.');
    $central = file_get_contents($consumer . '/CHANGELOG.md');
    verify(!str_contains($central, 'fast-forward-changelog:'), 'Non-Git history includes synthetic metadata.');
    verify($preview['notes'] === cli($binary, $consumer, $environment, ['notes', '--source=tags'])['stdout'], 'Non-Git default notes changed planned Markdown.');
    $journals = glob($environment['TMPDIR'] . '/fast-forward-changelog/*/release-plan.json');
    verify([] === $journals, 'Successful non-Git application retained a recovery journal.');
    fwrite(STDOUT, "Non-Git clean consolidation PASS: {$consumer}\n");
}

try {
    foreach (['home', 'tmp', 'composer-cache', 'local-composer', 'global-composer', 'local-installation', 'local-consumer', 'global-consumer', 'package'] as $name) {
        mkdir($fixtureRoot . '/' . $name, 0700, true);
    }
    $localEnv = environment($fixtureRoot, $fixtureRoot . '/local-composer');
    $globalEnv = environment($fixtureRoot, $fixtureRoot . '/global-composer');
    exportSource($packageRoot, $fixtureRoot . '/package', $localEnv);
    $manifest = ['name' => 'fixture/changelog-consumer', 'type' => 'project', 'license' => 'MIT',
        'repositories' => [['type' => 'path', 'url' => $fixtureRoot . '/package', 'options' => ['symlink' => false, 'versions' => ['fast-forward/changelog' => 'dev-main']]]],
        'require' => ['fast-forward/changelog' => 'dev-main'], 'config' => ['allow-plugins' => false, 'platform' => ['php' => '8.5.0']]];
    $local = $fixtureRoot . '/local-installation';
    $global = $fixtureRoot . '/global-composer';
    file_put_contents($local . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    file_put_contents($global . '/composer.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    fwrite(STDOUT, "Packaging fixtures: {$fixtureRoot}\nInstalling local runtime...\n");
    execute(['composer', 'install', '--no-dev', '--no-plugins', '--no-scripts', '--prefer-dist', '--no-interaction', '--no-progress'], $local, $localEnv);
    execute(['composer', 'check-platform-reqs', '--no-dev'], $local, $localEnv);
    runtimeGraph($local);
    exercise($local, $fixtureRoot . '/local-consumer', $localEnv);
    exerciseNonGit($local, $fixtureRoot . '/non-git-consumer', $localEnv);
    fwrite(STDOUT, "Installing native Composer global runtime...\n");
    execute(['composer', 'global', 'install', '--no-dev', '--no-plugins', '--no-scripts', '--prefer-dist', '--no-interaction', '--no-progress'], $fixtureRoot . '/global-consumer', $globalEnv);
    execute(['composer', 'global', 'check-platform-reqs', '--no-dev'], $fixtureRoot . '/global-consumer', $globalEnv);
    runtimeGraph($global);
    exercise($global, $fixtureRoot . '/global-consumer', $globalEnv);
    fwrite(STDOUT, sprintf("Packaging PASS: %d assertions, %d commands; local/global Composer installations and Git consumers are retained only at %s. No real API/tag/release operation ran.\n", $assertions, $commands, $fixtureRoot));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nFixture retained for diagnosis: " . $fixtureRoot . "\n");
    exit(1);
}
