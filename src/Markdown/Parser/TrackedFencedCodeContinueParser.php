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

namespace FastForward\Changelog\Markdown\Parser;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Parser\Block\AbstractBlockContinueParser;
use League\CommonMark\Parser\Block\BlockContinue;
use League\CommonMark\Parser\Block\BlockContinueParserInterface;
use League\CommonMark\Parser\Cursor;

/** Adds closure evidence without reimplementing the dependency's container or delimiter rules. */
final class TrackedFencedCodeContinueParser extends AbstractBlockContinueParser
{
    public const string EXPLICIT_CLOSER = 'fast_forward.explicit_closer';

    /**
     * Upstream fenced-code parser whose operations are delegated unchanged.
     *
     * @param BlockContinueParserInterface $inner Upstream fenced-code parser whose operations are delegated unchanged.
     */
    public function __construct(
        private readonly BlockContinueParserInterface $inner,
    ) {}

    /**
     * Original upstream node retaining its source range and literal payload.
     *
     * @return FencedCode Original upstream node retaining its source range and literal payload.
     */
    public function getBlock(): FencedCode
    {
        return $this->inner->getBlock();
    }

    /**
     * Records a real delimiter only when the dependency explicitly finalizes this block.
     *
     * @param  Cursor                       $cursor            Current line within the upstream container context.
     * @param  BlockContinueParserInterface $activeBlockParser Current leaf parser supplied by CommonMark.
     * @return BlockContinue|null           Unchanged continuation decision; EOF and container termination never forge closure evidence.
     */
    public function tryContinue(Cursor $cursor, BlockContinueParserInterface $activeBlockParser): ?BlockContinue
    {
        $result = $this->inner->tryContinue($cursor, $activeBlockParser);

        if ($result?->isFinalize()) {
            $this->getBlock()->data->set(self::EXPLICIT_CLOSER, true);
        }

        return $result;
    }

    /**
     * Upstream content slice whose literal whitespace must remain unchanged.
     *
     * @param  string $line Upstream content slice whose literal whitespace must remain unchanged.
     * @return void   Completion after the original parser receives the unchanged content.
     */
    public function addLine(string $line): void
    {
        $this->inner->addLine($line);
    }

    /**
     * Completes the original parser without inferring whether closure came from EOF or a delimiter.
     *
     * @return void Completion after the delegated parser finalizes its original payload.
     */
    public function closeBlock(): void
    {
        $this->inner->closeBlock();
    }
}
