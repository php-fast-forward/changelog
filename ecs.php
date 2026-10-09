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

use PhpCsFixer\Fixer\FunctionNotation\MethodArgumentSpaceFixer;
use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use PhpCsFixer\Fixer\Import\OrderedImportsFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocAlignFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocIndentFixer;
use PhpCsFixer\Fixer\Phpdoc\PhpdocTrimFixer;
use PhpCsFixer\Fixer\Whitespace\BlankLineBeforeStatementFixer;
use Symplify\CodingStandard\Fixer\LineLength\LineLengthFixer;
use Symplify\CodingStandard\Fixer\Spacing\StandaloneLineConstructorParamFixer;
use Symplify\CodingStandard\Fixer\Spacing\StandaloneLineSymfonyAttributeParamFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __DIR__ . '/scripts',
        __DIR__ . '/bin/changelog',
        __DIR__ . '/rector.php',
        __DIR__ . '/ecs.php',
    ])
    ->withCache(__DIR__ . '/.build/ecs')
    ->withPreparedSets(perCs: true)
    ->withRules([
        NoUnusedImportsFixer::class,
        PhpdocAlignFixer::class,
        PhpdocIndentFixer::class,
        PhpdocTrimFixer::class,
        StandaloneLineConstructorParamFixer::class,
        StandaloneLineSymfonyAttributeParamFixer::class,
    ])
    ->withConfiguredRule(BlankLineBeforeStatementFixer::class, [
        'statements' => ['return', 'throw', 'continue', 'break'],
    ])
    ->withConfiguredRule(LineLengthFixer::class, [
        'line_length' => 120,
        'break_long_lines' => true,
        'inline_short_lines' => false,
    ])
    ->withConfiguredRule(MethodArgumentSpaceFixer::class, [
        'on_multiline' => 'ignore',
        'attribute_placement' => 'standalone',
    ])
    ->withConfiguredRule(OrderedImportsFixer::class, [
        'imports_order' => ['class', 'function', 'const'],
        'sort_algorithm' => 'alpha',
    ]);
