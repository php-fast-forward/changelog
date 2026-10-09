<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license https://opensource.org/licenses/MIT MIT License
 * @see https://github.com/php-fast-forward/changelog
 * @see https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Console;

use InvalidArgumentException;
use Symfony\Component\Filesystem\Filesystem;

/** Preserves stable machine output while appending guarded scalar records to the caller-owned Action file. */
final readonly class GitHubOutputWriter implements GitHubOutputWriterInterface
{
    private const array SCALARS = ['status', 'state', 'version', 'pull_request', 'url', 'head_sha', 'plan_id',
        'fragments', 'maintenance', 'path', 'tag', 'sha'];

    /** Captures the filesystem and explicit output path; construction reads no environment or host state. */
    public function __construct(
        private Filesystem $filesystem,
        private ?string $outputFile = null,
    ) {}

    /** Validates the entire payload before one append; null output paths produce stdout-only results. */
    public function write(array $result): string
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines = ['result=' . $json];
        foreach (self::SCALARS as $key) {
            if (! array_key_exists($key, $result) || null === $result[$key]) {
                continue;
            }
            $value = $result[$key];
            if (! is_scalar($value)) {
                throw new InvalidArgumentException('An Action scalar output must be a string, number or boolean.');
            }
            $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            if (1 === preg_match('/[\\r\\n\\0]/', $value)) {
                throw new InvalidArgumentException('An Action scalar output contains an unsafe line separator.');
            }
            $lines[] = str_replace('_', '-', $key) . '=' . $value;
        }
        if (null !== $this->outputFile) {
            if ('' === $this->outputFile) {
                throw new InvalidArgumentException('The Action output path must not be empty.');
            }
            $this->filesystem->appendToFile($this->outputFile, implode("\n", $lines) . "\n", true);
        }

        return $json;
    }
}
