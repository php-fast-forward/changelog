<?php

declare(strict_types=1);

/**
 * Development tooling for the standalone Fast Forward Changelog package.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see https://github.com/php-fast-forward/changelog
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

$paths = array_slice($argv, 1);

if ([] === $paths) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));

    foreach ($iterator as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            $paths[] = $file->getPathname();
        }
    }
}

sort($paths);
$parser = new ParserFactory()->createForNewestSupportedVersion();
$finder = new NodeFinder();
$failures = [];
$methods = 0;

foreach ($paths as $path) {
    try {
        $contents = file_get_contents($path);

        if (false === $contents) {
            throw new RuntimeException('Unable to read the source file.');
        }

        $nodes = $parser->parse($contents) ?? [];
    } catch (Throwable $exception) {
        $failures[] = $path . ': ' . $exception->getMessage();

        continue;
    }

    foreach ($finder->findInstanceOf($nodes, Node\Stmt\ClassMethod::class) as $method) {
        ++$methods;
        $doc = $method->getDocComment()?->getText() ?? '';
        $lines = preg_split('/\R/', $doc) ?: [];
        $description = [];

        foreach ($lines as $line) {
            $line = trim((string) preg_replace('/^\s*\/?\*+\/?\s?|\s*\*\/$/', '', $line));

            if (str_starts_with($line, '@')) {
                break;
            }

            if ('' !== $line) {
                $description[] = $line;
            }
        }

        $summary = implode(' ', $description);
        $normalizedName = strtolower((string) preg_replace('/[^a-z]/i', '', $method->name->toString()));
        $normalizedSummary = strtolower((string) preg_replace('/[^a-z]/i', '', $summary));

        if (strlen($summary) < 20 || $normalizedName === $normalizedSummary || str_contains($doc, '{@inheritdoc}')) {
            $failures[] = sprintf('%s:%d %s() requires an explanatory contract summary.', $path, $method->getStartLine(), $method->name->toString());
        }
    }
}

if (0 === $methods) {
    $failures[] = 'No production methods were inspected.';
}

if ([] !== $failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, sprintf("PHPDoc summaries verified for %d production methods. Review still checks semantic accuracy.\n", $methods));
