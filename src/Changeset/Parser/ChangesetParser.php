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

namespace FastForward\Changelog\Changeset\Parser;

use FastForward\Changelog\Changeset\Category;
use FastForward\Changelog\Changeset\ChangesetParseResult;
use FastForward\Changelog\Changeset\Factory\ChangesetFactoryInterface;
use FastForward\Changelog\Changeset\Factory\ChangesetParseResultFactoryInterface;
use FastForward\Changelog\Changeset\VersionImpact;

/**
 * Parses a restricted scalar frontmatter without executing PHP or requiring YAML.
 *
 * Unknown keys, duplicate identities and malformed scalars are errors. Legacy
 * version and pull-request keys are normalized only when unambiguous. Writers
 * MUST serialize the resulting effective type using the canonical field.
 */
final readonly class ChangesetParser implements ChangesetParserInterface
{
    /** @var list<string> Supported canonical and migration-only metadata keys. */
    private const array ALLOWED_KEYS = ['category', 'type', 'issue', 'pull_request', 'author', 'version', 'pull-request'];

    /**
     * Injects construction boundaries so parsing remains independent of I/O.
     */
    public function __construct(
        private ChangesetFactoryInterface $changesetFactory,
        private ChangesetParseResultFactoryInterface $resultFactory,
    ) {}

    /**
     * Parses one stable filename and retains all independent schema diagnostics.
     *
     * Missing type in historical input uses the category default. Explicit type
     * or legacy version overrides it in either direction. Only blank boundary
     * lines are removed from the body; meaningful Markdown whitespace remains.
     */
    public function parse(string $path, string $contents): ChangesetParseResult
    {
        $normalizedPath = str_replace('\\', '/', $path);
        $separator = strrpos($normalizedPath, '/');
        $id = false === $separator ? $normalizedPath : substr($normalizedPath, $separator + 1);
        $errors = [];

        if (1 !== preg_match(self::FILENAME_PATTERN, $id)) {
            $errors[] = 'Filename must match ^[a-z0-9]+(?:-[a-z0-9]+)*\\.md$.';
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));

        if ('---' !== ($lines[0] ?? null)) {
            $errors[] = 'Fragment must start with a frontmatter delimiter.';

            return $this->resultFactory->invalid($id, $errors);
        }

        $closingLine = null;

        foreach (array_slice($lines, 1, null, true) as $index => $line) {
            if ('---' === $line) {
                $closingLine = $index;

                break;
            }
        }

        if (null === $closingLine) {
            $errors[] = 'Fragment frontmatter must have a closing delimiter.';

            return $this->resultFactory->invalid($id, $errors);
        }

        $metadata = [];

        foreach (array_slice($lines, 1, $closingLine - 1) as $line) {
            if (1 !== preg_match('/^(?<key>[a-z][a-z_-]*):[ ]*(?<value>.*)$/', $line, $matches)) {
                $errors[] = sprintf('Invalid frontmatter line "%s".', $line);

                continue;
            }

            $key = $matches['key'];

            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                $errors[] = sprintf('Unknown frontmatter key "%s".', $key);

                continue;
            }

            $key = 'pull-request' === $key ? 'pull_request' : $key;

            if (array_key_exists($key, $metadata)) {
                $errors[] = sprintf('Duplicate frontmatter key "%s".', $key);

                continue;
            }

            $metadata[$key] = $this->parseQuotedScalar(trim($matches['value']));
        }

        if (! array_key_exists('category', $metadata)) {
            $errors[] = 'Missing required frontmatter key "category".';
        }

        $category = Category::tryFrom($metadata['category'] ?? '');

        if (! $category instanceof Category) {
            $errors[] = 'Category must be one of added, changed, deprecated, removed, fixed, security.';
        }

        $issue = $this->parseReference($metadata['issue'] ?? 'null');
        $pullRequest = $this->parseReference($metadata['pull_request'] ?? 'null');

        if (false === $issue) {
            $errors[] = 'Issue must be a positive integer or null.';
        }

        if (false === $pullRequest) {
            $errors[] = 'Pull request must be a positive integer or null.';
        }

        $author = $metadata['author'] ?? 'null';
        $author = 'null' === $author ? null : (str_starts_with($author, '@') ? substr($author, 1) : $author);

        if (null !== $author && 1 !== preg_match(self::AUTHOR_PATTERN, $author)) {
            $errors[] = 'Author must be a GitHub login or null.';
        }

        $type = null;

        foreach (['type', 'version'] as $impactKey) {
            if (array_key_exists($impactKey, $metadata) && ! VersionImpact::tryFrom($metadata[$impactKey]) instanceof VersionImpact) {
                $errors[] = sprintf('%s must be one of major, minor, patch.', ucfirst($impactKey));
            }
        }

        if (array_key_exists('type', $metadata)
            && array_key_exists('version', $metadata)
            && $metadata['type'] !== $metadata['version']
        ) {
            $errors[] = 'Type and legacy version must not declare conflicting impacts.';
        }

        if (array_key_exists('type', $metadata) || array_key_exists('version', $metadata)) {
            $type = VersionImpact::tryFrom($metadata['type'] ?? $metadata['version']);
        } elseif ($category instanceof Category) {
            $type = $category->inferredImpact();
        }

        $body = array_slice($lines, $closingLine + 1);

        while ([] !== $body && '' === trim($body[0])) {
            array_shift($body);
        }

        while ([] !== $body && '' === trim(array_last($body))) {
            array_pop($body);
        }

        $description = implode("\n", $body);
        $visibleBody = trim((string) preg_replace('/<!--.*?(?:-->|\z)/s', '', $description));

        if ('' === $visibleBody) {
            $errors[] = 'Fragment body must contain non-comment Markdown text.';
        }

        if ([] !== $errors) {
            return $this->resultFactory->invalid($id, $errors);
        }

        $changeset = $this->changesetFactory->create($id, $category, $issue, $pullRequest, $author, $description, $type);

        return $this->resultFactory->valid($changeset);
    }

    /**
     * Reads an optional positive reference without accepting signs or overflow.
     *
     * @return positive-int|null|false False represents a malformed supplied scalar.
     */
    private function parseReference(string $value): int|false|null
    {
        if ('null' === $value) {
            return null;
        }

        if (1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /**
     * Accepts simple single or double quoted scalars without escape sequences.
     *
     * Anything else remains unchanged for typed validation to reject; this is
     * deliberately not a general YAML parser or an executable configuration.
     */
    private function parseQuotedScalar(string $value): string
    {
        if (1 === preg_match('/^(?:"(?<double>[^"\\\\]*)"|\'(?<single>[^\'\\\\]*)\')$/', $value, $matches)) {
            return $matches['double'] !== '' ? $matches['double'] : ($matches['single'] ?? '');
        }

        return $value;
    }
}
