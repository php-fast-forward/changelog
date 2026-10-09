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

namespace FastForward\Changelog\History\Factory;

use FastForward\Changelog\History\HistoryRelease;
use FastForward\Changelog\Validator\ReleaseDateValidatorInterface;
use InvalidArgumentException;

/** Validates semantic identities and calendar dates before constructing history values. */
final readonly class HistoryReleaseFactory implements HistoryReleaseFactoryInterface
{
    /** Shares the clock-free calendar validator with release preparation and imported history. */
    public function __construct(
        private ReleaseDateValidatorInterface $dates,
    ) {}

    /** Creates a release without reading a clock or deriving unknown provenance. */
    public function create(
        string $version,
        ?string $date = null,
        ?string $dateSource = null,
        string $body = '',
        ?string $heading = null,
        string $ending = '',
    ): HistoryRelease {
        $version = preg_replace('/^[vV](?=\d)/', '', $version);
        $identifier = '(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)';
        $pattern = '/\A(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)'
            . '(?:-' . $identifier . '(?:\.' . $identifier . ')*)?'
            . '(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\z/';

        if ('unreleased' !== $version && 1 !== preg_match($pattern, $version)) {
            throw new InvalidArgumentException('History release versions must be strict semantic versions.');
        }

        if (null !== $date) {
            $this->dates->validate($date);
        }

        return new HistoryRelease($version, $date, $dateSource, $body, $heading, $ending);
    }
}
