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
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * Reads explanatory prose before the first tag without treating tags as a summary.
 *
 * @param string $doc Original method DocBlock, including its delimiters.
 *
 * @return string Whitespace-normalized prose used by the existing summary rule.
 */
function phpdocSummary(string $doc): string
{
    $description = [];

    foreach (preg_split('/\R/', $doc) ?: [] as $line) {
        $line = trim((string) preg_replace('/^\s*\/?\*+\/?\s?|\s*\*\/$/', '', $line));

        if (str_starts_with($line, '@')) {
            break;
        }

        if ('' !== $line) {
            $description[] = $line;
        }
    }

    return implode(' ', $description);
}

/**
 * Checks that every declared argument has one typed, described parameter tag.
 *
 * @param Node\Stmt\ClassMethod $method   Parsed signature that owns the documented arguments.
 * @param PhpDocNode            $document Parsed DocBlock, retaining invalid and duplicate tags.
 *
 * @return list<string> Contract failures without source-location prefixes.
 */
function phpdocParameterFailures(Node\Stmt\ClassMethod $method, PhpDocNode $document): array
{
    $expected = [];

    foreach ($method->params as $parameter) {
        $expected['$' . $parameter->var->name] = 0;
    }

    $failures = [];

    foreach ($document->getTagsByName('@param') as $tag) {
        $parameter = $tag->value;

        if (! $parameter instanceof ParamTagValueNode) {
            $failures[] = 'requires valid @param type $name description syntax.';

            continue;
        }

        $name = $parameter->parameterName;

        if (! array_key_exists($name, $expected)) {
            $failures[] = 'documents unknown parameter ' . $name . '.';

            continue;
        }

        ++$expected[$name];

        if ('' === trim($parameter->description)) {
            $failures[] = '@param ' . $name . ' requires an explanatory description.';
        }
    }

    foreach ($expected as $name => $count) {
        if (1 !== $count) {
            $failures[] = sprintf('requires exactly one @param for %s; found %d.', $name, $count);
        }
    }

    return $failures;
}

/**
 * Requires a described return contract while exempting construction and destruction.
 *
 * @param Node\Stmt\ClassMethod $method   Signature used to identify constructor and destructor exceptions.
 * @param PhpDocNode            $document Parsed tags whose type and prose must remain distinguishable.
 *
 * @return list<string> Missing, duplicate, malformed or undescribed return-tag failures.
 */
function phpdocReturnFailures(Node\Stmt\ClassMethod $method, PhpDocNode $document): array
{
    $tags = $document->getTagsByName('@return');
    $optional = in_array(strtolower($method->name->toString()), ['__construct', '__destruct'], true);

    if ([] === $tags && $optional) {
        return [];
    }

    if (1 !== count($tags)) {
        return [sprintf('requires exactly one @return type description; found %d.', count($tags))];
    }

    $return = reset($tags)->value;

    if (! $return instanceof ReturnTagValueNode) {
        return ['requires valid @return type description syntax.'];
    }

    if ('' === trim($return->description)) {
        return ['@return requires an explanatory description.'];
    }

    return [];
}

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
$config = new ParserConfig([]);
$lexer = new Lexer($config);
$constantParser = new ConstExprParser($config);
$typeParser = new TypeParser($config, $constantParser);
$docParser = new PhpDocParser($config, $typeParser, $constantParser);
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
        $summary = phpdocSummary($doc);
        $normalizedName = strtolower((string) preg_replace('/[^a-z]/i', '', $method->name->toString()));
        $normalizedSummary = strtolower((string) preg_replace('/[^a-z]/i', '', $summary));

        if (strlen($summary) < 20 || $normalizedName === $normalizedSummary || str_contains($doc, '{@inheritdoc}')) {
            $failures[] = sprintf(
                '%s:%d %s() requires an explanatory contract summary.',
                $path,
                $method->getStartLine(),
                $method->name->toString(),
            );
        }

        try {
            $document = $docParser->parse(new TokenIterator($lexer->tokenize('' === $doc ? '/** */' : $doc)));
            $contracts = [
                ...phpdocParameterFailures($method, $document),
                ...phpdocReturnFailures($method, $document),
            ];
        } catch (Throwable $exception) {
            $contracts = ['has an invalid DocBlock: ' . $exception->getMessage()];
        }

        foreach ($contracts as $failure) {
            $failures[] = sprintf(
                '%s:%d %s() %s',
                $path,
                $method->getStartLine(),
                $method->name->toString(),
                $failure,
            );
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

fwrite(
    STDOUT,
    sprintf("PHPDoc contracts verified for %d production methods. Review still checks semantic accuracy.\n", $methods),
);
