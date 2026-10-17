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
 */

namespace FastForward\Changelog\Release;

use FastForward\Changelog\Release\Factory\ReleaseExceptionFactory;
use FastForward\Changelog\Release\Factory\ReleaseExceptionFactoryInterface;

/** Parses only complete transaction trailers; their presence never grants publication authority. */
final readonly class ReleaseMetadataParser
{
    /**
     * Retains the existing controlled metadata failure contract without observing external state.
     *
     * @param ReleaseExceptionFactoryInterface $exceptions Constructs controlled domain diagnostics without exposing underlying host details.
     */
    public function __construct(
        private ReleaseExceptionFactoryInterface $exceptions = new ReleaseExceptionFactory(),
    ) {}

    /**
     * Rejects malformed, duplicate and partial trailers; unrelated prose contains no transaction.
     *
     * @param string $message Complete commit message inspected for the scalar Changelog transaction trailers.
     *
     * @return array{base_sha:string,plan_id:string,output_sha256:string,options_sha256:string}|null Complete scalar transaction metadata, or null when the message has no transaction trailers.
     *
     * @throws \RuntimeException When any recognized trailer is malformed, repeated or part of an incomplete transaction.
     */
    public function parse(string $message): ?array
    {
        $fields = ['Changelog-Base' => 'base_sha', 'Changelog-Plan' => 'plan_id',
            'Changelog-Output' => 'output_sha256', 'Changelog-Options' => 'options_sha256'];
        $data = [];
        foreach (explode("\n", str_replace("\r\n", "\n", $message)) as $line) {
            foreach ($fields as $trailer => $field) {
                if (!str_starts_with($line, $trailer . ':')) {
                    continue;
                }
                $pattern = 'base_sha' === $field ? '(?:[a-f0-9]{40}|[a-f0-9]{64})' : '[a-f0-9]{64}';
                if (isset($data[$field]) || 1 !== preg_match(
                    '~^' . $trailer . ': (' . $pattern . ')$~D',
                    $line,
                    $match,
                )) {
                    throw $this->exceptions->failure(
                        'Release commit metadata contains malformed or duplicate trailers.',
                    );
                }
                $data[$field] = $match[1];
            }
        }
        if ([] === $data) {
            return null;
        }
        if (count($data) !== count($fields)) {
            throw $this->exceptions->failure('Release commit metadata is incomplete.');
        }

        return array_replace(array_fill_keys(array_values($fields), ''), $data);
    }
}
