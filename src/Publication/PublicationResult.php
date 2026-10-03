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
     * @param list<string> $actions create_tag/create_release attempts, or dry-run proposals
     */
    public function __construct(
        public string $state,
        public ?string $version,
        public ?string $tag,
        public string $sha,
        public ?string $url,
        public array $actions,
    ) {}

    /** Exposes a deterministic object-shaped summary to commands and automation. */
    public function summary(): array
    {
        return ['state' => $this->state, 'version' => $this->version, 'tag' => $this->tag,
            'sha' => $this->sha, 'url' => $this->url, 'actions' => $this->actions];
    }
}
