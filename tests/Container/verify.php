<?php

declare(strict_types=1);

/** Executes the actual packaged image with synthetic credentials, isolated Git and a local-only Docker endpoint. */

$image = $argv[1] ?? 'fast-forward-changelog:ci';
$expectedVersion = $argv[2] ?? null;
$fixtureRoot = sys_get_temp_dir() . '/changelog-container-' . bin2hex(random_bytes(8));
mkdir($fixtureRoot, 0700, true);
mkdir($fixtureRoot . '/home', 0700);
mkdir($fixtureRoot . '/project', 0700);
$assertions = 0;
$commands = 0;

/** Captures both streams in the disposable fixture without changing caller environment or shell state. */
function execute(array $arguments, array $environment, int $expected = 0): array
{
    global $fixtureRoot, $commands;
    ++$commands;
    $stdout = $fixtureRoot . '/command-' . $commands . '.out';
    $stderr = $fixtureRoot . '/command-' . $commands . '.err';
    $process = proc_open($arguments, [0 => ['file', '/dev/null', 'r'],
        1 => ['file', $stdout, 'w'], 2 => ['file', $stderr, 'w']], $pipes, $fixtureRoot, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot launch the container fixture command.');
    }
    $exit = proc_close($process);
    $result = ['stdout' => file_get_contents($stdout), 'stderr' => file_get_contents($stderr), 'exit' => $exit];
    if ($exit !== $expected) {
        throw new RuntimeException('Unexpected fixture exit ' . $exit . ': ' . implode(' ', $arguments) . "\n" . $result['stdout'] . $result['stderr']);
    }
    return $result;
}

/** Counts a behavioral assertion and leaves a precise, recoverable fixture on failure. */
function verify(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** Excludes Git administration while retaining all actual consumer bytes. */
function snapshot(string $directory): array
{
    $state = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = substr($file->getPathname(), strlen($directory) + 1);
        if (!str_starts_with($path, '.git/') && $file->isFile()) {
            $state[$path] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($state);
    return $state;
}

try {
    $context = json_decode(execute(['docker', 'context', 'inspect'], getenv())['stdout'], true, 512, JSON_THROW_ON_ERROR);
    $endpoint = $context[0]['Endpoints']['docker']['Host'] ?? '';
    verify(is_string($endpoint) && str_starts_with($endpoint, 'unix://'), 'Container verification requires an explicitly local Unix Docker endpoint.');
    $environment = getenv();
    foreach (['GITHUB_TOKEN', 'GH_TOKEN', 'FF_CHANGELOG_TOKEN', 'FF_CHANGELOG_INPUTS', 'GITHUB_OUTPUT',
        'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG', 'DOCKER_CERT_PATH', 'DOCKER_TLS_VERIFY',
        'GIT_DIR', 'GIT_WORK_TREE', 'GIT_CONFIG', 'GIT_CONFIG_COUNT', 'GIT_CONFIG_PARAMETERS', 'GIT_SSH', 'GIT_SSH_COMMAND',
        'GIT_ASKPASS', 'SSH_ASKPASS', 'SSH_AUTH_SOCK'] as $name) {
        unset($environment[$name]);
    }
    $environment = [...$environment, 'HOME' => $fixtureRoot . '/home', 'XDG_CONFIG_HOME' => $fixtureRoot . '/home/config',
        'GIT_CONFIG_NOSYSTEM' => '1', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_TERMINAL_PROMPT' => '0',
        'GIT_AUTHOR_NAME' => 'Container Fixture', 'GIT_AUTHOR_EMAIL' => 'fixture@example.invalid',
        'GIT_COMMITTER_NAME' => 'Container Fixture', 'GIT_COMMITTER_EMAIL' => 'fixture@example.invalid',
        'GIT_AUTHOR_DATE' => '2026-01-01T00:00:00+00:00', 'GIT_COMMITTER_DATE' => '2026-01-01T00:00:00+00:00'];
    $docker = static fn(array $args, int $expected = 0): array => execute(['docker', '--host', $endpoint, ...$args], $environment, $expected);
    $cli = static fn(array $args, int $expected = 0): array => $docker(['run', '--rm', '--network=none',
        '--volume', $fixtureRoot . ':/github/workspace', '--workdir', '/github/workspace',
        '--env', 'FF_CHANGELOG_TOKEN=fixture-action-token', '--env', 'GITHUB_TOKEN=fixture-fallback-token',
        '--env', 'GITHUB_API_URL=https://api.example.invalid', '--env', 'GITHUB_OUTPUT=/github/workspace/action-output',
        $image, ...$args], $expected);
    $git = static fn(array $args): string => execute(['git', '-C', $fixtureRoot . '/project', ...$args], $environment)['stdout'];

    $config = json_decode($docker(['image', 'inspect', $image])['stdout'], true, 512, JSON_THROW_ON_ERROR)[0];
    verify(['/usr/local/bin/changelog'] === $config['Config']['Entrypoint'], 'The image must execute the packaged CLI directly from PATH.');
    $runtime = $docker(['run', '--rm', '--network=none', '--entrypoint', 'php', $image, '-r',
        'require "/usr/local/vendor/autoload.php"; echo json_encode(["php" => PHP_VERSION, "version" => Composer\\InstalledVersions::getPrettyVersion("fast-forward/changelog"), "src" => is_file("/usr/local/src/Console/Changelog.php"), "dev" => Composer\\InstalledVersions::isInstalled("phpunit/phpunit"), "composer" => is_file("/usr/local/bin/composer"), "script" => is_file("/usr/local/scripts/automation.php")]);']);
    $runtime = json_decode($runtime['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify(str_starts_with($runtime['php'], '8.5.'), 'The runtime must remain PHP 8.5.');
    verify($runtime['src'], 'Source code must be installed under /usr/local/src.');
    if (null !== $expectedVersion) {
        verify($expectedVersion === $runtime['version'], 'The build argument must match the installed package version.');
        verify(1 === preg_match('/(?<![0-9A-Za-z.+-])' . preg_quote($expectedVersion, '/') . '(?![0-9A-Za-z.+-])/', $cli(['--version'])['stdout']), 'The direct CLI must report the complete selected build version.');
    }
    $pathCommand = $docker(['run', '--rm', '--network=none', '--entrypoint', 'changelog', $image, '--version'])['stdout'];
    verify(str_contains($pathCommand, 'Fast Forward Changelog'), 'The installed executable must resolve through PATH.');
    verify(!$runtime['dev'] && !$runtime['composer'] && !$runtime['script'], 'The image must contain installed production dependencies without test tools, Composer or an action script.');
    $listing = $cli(['list', '--raw'])['stdout'];
    foreach (['add', 'check', 'status', 'version', 'notes', 'publish', 'backfill', 'format', 'github'] as $name) {
        verify(1 === preg_match('/^' . preg_quote($name, '/') . '\\s+/m', $listing), 'Missing packaged CLI command: ' . $name);
    }
    $help = $cli(['github', '--help'])['stdout'];
    verify(str_contains($help, '--expected-head-sha') && str_contains($help, '--dry-run'), 'Automation help must expose exact authority and preview flags.');

    $git(['init', '--initial-branch=main']);
    file_put_contents($fixtureRoot . '/project/CHANGELOG.md', "# Changelog\n\n## [0.1.0] - 2026-01-01\n\n### Added\n\n- Published fixture notes.\n");
    $git(['add', '--all']);
    $git(['commit', '--message', 'test: baseline']);
    $git(['tag', 'v0.1.0']);
    file_put_contents($fixtureRoot . '/project/CHANGELOG.md', "# Changelog\n\n## [Unreleased]\n\n### Fixed\n\n- Legacy pending fixture note.\n\n## [0.1.0] - 2026-01-01\n\n### Added\n\n- Published fixture notes.\n");
    $git(['add', '--all']);
    $git(['commit', '--message', 'test: pending source']);
    $base = trim($git(['rev-parse', 'HEAD']));
    $cli(['add', "Container <info>literal</info> contribution.\n\n### Fixed\n\nKeep the nested heading.", '--name=container.md', '--cwd=project']);
    $git(['add', '--all']);
    $git(['commit', '--message', 'test: approve fragment']);
    $before = snapshot($fixtureRoot . '/project');

    $checked = json_decode($cli(['github', 'check', '--cwd=project', '--since=' . $base, '--source=tags'])['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify('valid' === $checked['status'] && 1 === $checked['fragments'], 'The native automation command must validate the committed contribution.');
    $outputs = file_get_contents($fixtureRoot . '/action-output');
    verify(str_contains($outputs, "status=valid\n") && str_contains($outputs, "fragments=1\n"), 'GitHub output records must match the CLI result.');
    $history = json_decode($cli(['github', 'history', '--cwd=project', '--operation=format', '--dry-run=true', '--source=tags'])['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify('dry-run' === $history['status'], 'Historical preview must use the same CLI and remain read-only.');
    verify($before === snapshot($fixtureRoot . '/project'), 'Check or history preview changed consumer bytes.');

    foreach ([
        ['github', '--cwd=project'],
        ['github', 'check', '--unknown-option', '--cwd=project'],
        ['github', 'check', '--cwd=project', '--since'],
        ['github', 'unknown', '--cwd=project'],
        ['github', 'history', '--cwd=project', '--operation=format', '--dry-run=invalid'],
        ['github', 'check', '--cwd=project', '--since=' . $base, '--dry-run=true'],
        ['github', 'publish', '--cwd=project', '--target-sha=short'],
        ['github', 'dependabot', '--cwd=project', '--pull-request=0', '--expected-head-sha=short'],
        ['github', 'version', '--cwd=project', '--dry-run=invalid'],
    ] as $arguments) {
        $outputs = file_get_contents($fixtureRoot . '/action-output');
        $result = $cli($arguments, 2);
        verify('' === $result['stdout'] && is_string(json_decode($result['stderr'], true, 512, JSON_THROW_ON_ERROR)['error']), 'Invalid automation input must produce only JSON diagnostics with exit 2.');
        verify($outputs === file_get_contents($fixtureRoot . '/action-output'), 'Rejected input appended an apparent success output.');
        verify($before === snapshot($fixtureRoot . '/project'), 'Rejected automation input changed consumer bytes.');
    }

    $plan = json_decode($cli(['version', '--cwd=project', '--dry-run', '--source=tags'])['stdout'], true, 512, JSON_THROW_ON_ERROR);
    verify('0.2.0' === $plan['next_version'], 'The installed image must calculate the fixture-only next version.');
    verify($before === snapshot($fixtureRoot . '/project'), 'Version preview changed consumer bytes.');
    $cli(['version', '--cwd=project', '--source=tags']);
    $notes = $cli(['notes', '0.2.0', '--cwd=project', '--source=tags'])['stdout'];
    verify($plan['notes'] === $notes, 'Installed-image notes differ from the exact approved preview.');
    verify(str_contains($notes, 'Container <info>literal</info> contribution.') && str_contains($notes, "  ### Fixed\n  \n  Keep the nested heading."), 'The installed image corrupted multiline fragment descriptions.');
    $history = file_get_contents($fixtureRoot . '/project/CHANGELOG.md');
    verify(!str_contains($history, '## [Unreleased]') && !str_contains($history, 'fast-forward-changelog:'), 'The image retained pending structure or technical metadata.');
    verify(1 === substr_count($notes, 'Legacy pending fixture note.'), 'The image discarded or duplicated pending descriptions.');
    verify(!is_file($fixtureRoot . '/project/.changelog/container.md') && !is_file($fixtureRoot . '/project/.changelog/release-plan.json'), 'The image must consume the fragment without a tracked plan.');
    fwrite(STDOUT, "Container CLI PASS: {$assertions} assertions, {$commands} commands; fixture={$fixtureRoot}\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\nFixture retained: {$fixtureRoot}\n");
    foreach (['out', 'err'] as $stream) {
        $log = $fixtureRoot . '/command-' . $commands . '.' . $stream;
        if (is_file($log)) {
            fwrite(STDERR, "Last fixture command {$stream}:\n" . substr(file_get_contents($log), 0, 4000) . "\n");
        }
    }
    exit(1);
}
