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
use FastForward\Changelog\Version\VersionImpact;

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
    private const array ALLOWED_KEYS = [
        'category',
        'type',
        'issue',
        'pull_request',
        'author',
        'version',
        'pull-request',
    ];

    /**
     * Injects construction boundaries so parsing remains independent of I/O.
     *
     * @param ChangesetFactoryInterface            $changesetFactory Constructs normalized fragment values only after schema validation succeeds.
     * @param ChangesetParseResultFactoryInterface $resultFactory    Builds successful or complete diagnostic-only parse results while retaining fragment identity.
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
     *
     * @param string $path     Source fragment path whose basename supplies the canonical fragment identity.
     * @param string $contents Complete fragment Markdown whose restricted scalar frontmatter and body are parsed together.
     *
     * @return ChangesetParseResult Validated immutable fragment or the complete independent schema diagnostics, retaining its canonical filename.
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

        if (1 !== preg_match('//u', $contents)) {
            $errors[] = 'Fragment contents must be valid UTF-8 for release plans and automation output.';

            return $this->resultFactory->invalid($id, $errors);
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $contents));

        if ('---' !== ($lines[0] ?? null)) {
            $errors[] = 'Fragment must start with a frontmatter delimiter.';

            return $this->resultFactory->invalid($id, $errors);
        }

        $closingLine = array_find_key(array_slice($lines, 1, null, true), fn($line) => '---' === $line);

        if (null === $closingLine) {
            $errors[] = 'Fragment frontmatter must have a closing delimiter.';

            return $this->resultFactory->invalid($id, $errors);
        }

        $metadata = $this->parseMetadata(array_slice($lines, 1, $closingLine - 1), $errors);

        $category = $this->parseCategory($metadata, $errors);

        [$issue, $pullRequest] = $this->parseReferences($metadata, $errors);

        $author = $this->parseAuthor($metadata, $errors);

        $type = $this->parseImpact($metadata, $category, $errors);

        $description = $this->parseDescription(array_slice($lines, $closingLine + 1), $errors);

        if ([] !== $errors) {
            return $this->resultFactory->invalid($id, $errors);
        }

        $changeset = $this->changesetFactory->create(
            $id,
            $category,
            $issue,
            $pullRequest,
            $author,
            $description,
            $type,
        );

        return $this->resultFactory->valid($changeset);
    }

    /**
     * Reads supported scalar metadata while retaining independent schema errors.
     *
     * @param list<string> $lines  Frontmatter lines between the two delimiters.
     * @param list<string> $errors Diagnostics appended in source order.
     *
     * @return array<string, string> Canonical keys and their unescaped scalar values.
     */
    private function parseMetadata(array $lines, array &$errors): array
    {
        $metadata = [];

        foreach ($lines as $line) {
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

        return $metadata;
    }

    /**
     * Resolves the required category without discarding other diagnostics.
     *
     * @param array<string, string> $metadata Parsed frontmatter with canonical key names.
     * @param list<string>          $errors   Diagnostics appended when category is missing or unknown.
     *
     * @return Category|null Recognized category, or null for invalid metadata.
     */
    private function parseCategory(array $metadata, array &$errors): ?Category
    {
        if (! array_key_exists('category', $metadata)) {
            $errors[] = 'Missing required frontmatter key "category".';
        }

        $category = Category::tryFrom($metadata['category'] ?? '');

        if (! $category instanceof Category) {
            $errors[] = 'Category must be one of added, changed, deprecated, removed, fixed, security.';
        }

        return $category;
    }

    /**
     * Parses optional issue and pull-request references independently.
     *
     * @param array<string, string> $metadata Parsed scalar issue and pull-request fields.
     * @param list<string>          $errors   Diagnostics appended for nonpositive or malformed references.
     *
     * @return array{0: positive-int|null|false, 1: positive-int|null|false} Issue and PR results; false marks an invalid scalar.
     */
    private function parseReferences(array $metadata, array &$errors): array
    {
        $issue = $this->parseReference($metadata['issue'] ?? 'null');
        $pullRequest = $this->parseReference($metadata['pull_request'] ?? 'null');

        if (false === $issue) {
            $errors[] = 'Issue must be a positive integer or null.';
        }

        if (false === $pullRequest) {
            $errors[] = 'Pull request must be a positive integer or null.';
        }

        return [$issue, $pullRequest];
    }

    /**
     * Normalizes an optional GitHub login and reports invalid spellings.
     *
     * @param array<string, string> $metadata Parsed scalar frontmatter.
     * @param list<string>          $errors   Diagnostics appended when the supplied login is invalid.
     *
     * @return string|null Supplied login without a leading at-sign, or null when omitted.
     */
    private function parseAuthor(array $metadata, array &$errors): ?string
    {
        $author = $metadata['author'] ?? 'null';
        $author = 'null' === $author ? null : (str_starts_with($author, '@') ? substr($author, 1) : $author);

        if (null !== $author && 1 !== preg_match(self::AUTHOR_PATTERN, $author)) {
            $errors[] = 'Author must be a GitHub login or null.';
        }

        return $author;
    }

    /**
     * Resolves explicit or inferred impact after checking legacy conflicts.
     *
     * @param array<string, string> $metadata Canonical type and optional legacy version fields.
     * @param Category|null         $category Category whose default applies when impact is omitted.
     * @param list<string>          $errors   Diagnostics appended for unknown or conflicting impacts.
     *
     * @return VersionImpact|null Effective impact, or null when the fields cannot resolve one.
     */
    private function parseImpact(array $metadata, ?Category $category, array &$errors): ?VersionImpact
    {
        $type = null;

        foreach (['type', 'version'] as $impactKey) {
            if (array_key_exists($impactKey, $metadata) && ! VersionImpact::tryFrom(
                $metadata[$impactKey],
            ) instanceof VersionImpact) {
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

        return $type;
    }

    /**
     * Trims blank boundary lines while preserving meaningful Markdown bytes.
     *
     * @param list<string> $body   Lines following the closing frontmatter delimiter.
     * @param list<string> $errors Diagnostics appended for empty text or unclosed fences.
     *
     * @return string Markdown body with its original internal spacing and line breaks.
     */
    private function parseDescription(array $body, array &$errors): string
    {
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

        if ($this->hasUnclosedReleaseFence($description)) {
            $errors[] = 'Fragment code fences must close before the end of their rendered release entry.';
        }

        return $description;
    }

    /**
     * Rejects fences that would hide following release structure after list wrapping.
     * Multiline descriptions receive two spaces per line in release notes. Match
     * the history codec's fence rules at that indentation without changing bytes
     * or rejecting deeper indented examples and ordinary inline backtick text.
     *
     * @param string $description Validated Markdown description whose meaningful whitespace is retained.
     *
     * @return bool Whether the list-wrapped description would leave a structural code fence open across later release content.
     */
    private function hasUnclosedReleaseFence(string $description): bool
    {
        if (! str_contains($description, "\n")) {
            return false;
        }
        $fence = null;
        foreach (explode("\n", $description) as $line) {
            $line = '  ' . $line;
            if (null !== $fence) {
                if (1 === preg_match(
                    '/^ {0,3}' . preg_quote($fence['character'], '/') . '{' . $fence['length'] . ',}[ \\t]*\\z/',
                    $line,
                )) {
                    $fence = null;
                }

                continue;
            }
            if (1 === preg_match('/^ {0,3}(`{3,}|~{3,})(.*)/', $line, $matches)
                && ('~' === $matches[1][0] || ! str_contains($matches[2], '`'))) {
                $fence = ['character' => $matches[1][0], 'length' => strlen($matches[1])];
            }
        }

        return null !== $fence;
    }

    /**
     * Reads an optional positive reference without accepting signs or overflow.
     *
     * @param string $value Captured terminal line or numeric reference being normalized without unrelated coercion.
     *
     * @return positive-int|null|false Positive parsed decimal, null for an omitted/null scalar, or false for malformed or overflowing input.
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
     *
     * @param string $value Captured terminal line or numeric reference being normalized without unrelated coercion.
     *
     * @return string Unquoted simple scalar when supported; otherwise the original spelling for downstream typed validation.
     */
    private function parseQuotedScalar(string $value): string
    {
        if (1 === preg_match('/^(?:"(?<double>[^"\\\\]*)"|\'(?<single>[^\'\\\\]*)\')$/', $value, $matches)) {
            return $matches['double'] !== '' ? $matches['double'] : ($matches['single'] ?? '');
        }

        return $value;
    }
}
