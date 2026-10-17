<?php

declare(strict_types=1);

use FastForward\Changelog\Repository\ReleaseChannelUpdater;
use Symfony\Component\HttpClient\HttpClient;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/ReleaseChannelUpdater.php';

if (4 !== $argc || 'php-fast-forward/changelog' !== ($argv[1] ?? null)) {
    fwrite(
        STDERR,
        "Usage: php .github/update-release-channels.php php-fast-forward/changelog v0.1.0 full-commit-sha\n",
    );
    exit(2);
}

$token = getenv('GH_TOKEN');
if (! is_string($token) || '' === $token) {
    fwrite(STDERR, "A scoped GH_TOKEN is required for release-channel operations.\n");
    exit(2);
}

try {
    $http = HttpClient::create(['headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/vnd.github+json',
        'X-GitHub-Api-Version' => '2026-03-10',
        'User-Agent' => 'FastForward-Changelog',
    ], 'timeout' => 30, 'max_duration' => 60, 'max_redirects' => 0]);
    $result = new ReleaseChannelUpdater($http)->update($argv[1], $argv[2], $argv[3]);
    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $error) {
    // Transport diagnostics can contain request context; keep authentication out of logs.
    $message = $error instanceof RuntimeException && __DIR__ . '/ReleaseChannelUpdater.php' === $error->getFile()
        ? $error->getMessage()
        : 'Release-channel request failed; inspect the current release and ref before retrying.';
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
