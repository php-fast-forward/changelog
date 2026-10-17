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

namespace FastForward\Changelog\Publication;

/** Captures publication state and planned/performed actions for stable machine output. */
final readonly class PublicationResult
{
    /**
     * Retains exact approved identity without loading host or remote state.
     *
     * @param string       $state   Publication or commit-status state selected by the owning operation.
     * @param ?string      $version Semantic version selected for comparison, planning or publication.
     * @param ?string      $tag     Exact release tag name selected by the transaction.
     * @param string       $sha     Complete immutable Git object identity selected for this evidence or result.
     * @param ?string      $url     Confirmed HTTPS PR or release URL, or null when no remote object is available.
     * @param list<string> $actions Ordered create_tag/create_release attempts, or the corresponding dry-run proposals.
     */
    public function __construct(
        public string $state,
        public ?string $version,
        public ?string $tag,
        public string $sha,
        public ?string $url,
        public array $actions,
    ) {}

    /**
     * Exposes a deterministic object-shaped summary to commands and automation.
     *
     * @return array Stable object-shaped machine result without further I/O.
     */
    public function summary(): array
    {
        return ['state' => $this->state, 'version' => $this->version, 'tag' => $this->tag,
            'sha' => $this->sha, 'url' => $this->url, 'actions' => $this->actions];
    }
}
