<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 * @see       https://github.com/php-fast-forward/changelog
 */

use FastForward\Changelog\Automation\AutomationRunnerInterface;
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;

use function FastForward\Container\container;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $inputs = json_decode(getenv('FF_CHANGELOG_INPUTS') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($inputs) || ([] !== $inputs && array_is_list($inputs))) {
        throw new InvalidArgumentException('Action inputs must be a JSON object.');
    }
    $token = $inputs['token'] ?? '';
    if (! is_string($token)) {
        throw new InvalidArgumentException('Action token must be a string.');
    }
    unset($inputs['token']);
    putenv('FF_CHANGELOG_INPUTS');
    $root = getcwd();
    if (false === $root) {
        throw new RuntimeException('Cannot determine the consumer working directory.');
    }
    $provider = new ChangelogServiceProvider($root, token: $token, apiUrl: getenv('GITHUB_API_URL') ?: 'https://api.github.com');
    $result = container($provider)->get(AutomationRunnerInterface::class)->run($argv[1] ?? '', $inputs);
    $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    fwrite(STDOUT, $json . "\n");
    $output = getenv('GITHUB_OUTPUT');
    if (false !== $output && '' !== $output) {
        $lines = ['result=' . $json];
        foreach (['status', 'state', 'version', 'pull_request', 'url', 'head_sha', 'plan_id', 'fragments', 'maintenance', 'path', 'tag', 'sha'] as $key) {
            if (! array_key_exists($key, $result) || null === $result[$key]) {
                continue;
            }
            $value = is_bool($result[$key]) ? ($result[$key] ? 'true' : 'false') : (string) $result[$key];
            if (preg_match('/[\r\n\0]/', $value)) {
                throw new RuntimeException('A scalar Action output contains an unsafe line separator.');
            }
            $lines[] = str_replace('_', '-', $key) . '=' . $value;
        }
        if (false === file_put_contents($output, implode("\n", $lines) . "\n", FILE_APPEND)) {
            throw new RuntimeException('Cannot write the Action output file.');
        }
    }
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    exit($exception instanceof InvalidArgumentException || $exception instanceof JsonException ? 2 : 1);
}
