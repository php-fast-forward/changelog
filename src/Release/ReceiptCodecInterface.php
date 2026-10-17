<?php

declare(strict_types=1);

/**
 * Standalone changeset and changelog tooling for Fast Forward PHP packages.
 *
 * This file is part of the fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see       https://github.com/php-fast-forward/changelog
 * @see       https://datatracker.ietf.org/doc/html/rfc2119
 */

namespace FastForward\Changelog\Release;

/** Encodes and validates canonical release evidence without accessing any managed file. */
interface ReceiptCodecInterface
{
    /**
     * Validates schema, paths, hashes and deterministic ID before exposing typed evidence.
     *
     * @param string $contents Complete serialized recovery JSON whose schema, paths, hashes and identity must be validated.
     *
     * @return ReleaseReceipt Schema-validated immutable recovery evidence with verified paths, hashes and identity.
     */
    public function decode(string $contents): ReleaseReceipt;

    /**
     * Validates evidence, orders its fields and includes its deterministic SHA-256 identity.
     *
     * @param array<string,mixed> $evidence Validated transaction fields from which canonical recovery JSON is encoded.
     *
     * @return string Canonical recovery JSON with ordered fields and its deterministic SHA-256 plan identity.
     */
    public function encode(array $evidence): string;
}
