# Requirements and dependency compatibility

Composer consumers receive the ready-built CLI, not its library dependency graph.
`require` declares PHP `^8.3`, Phar, zlib, iconv, JSON and tokenizer. The bundled
mbstring polyfill supplies that API where the native extension is unavailable.
Git operations need Git; generated-file HTTP streaming needs the supported cURL
backend. See [compiled distribution](phar.md) for the build and runtime contract.

## Private runtime and source development

All libraries are maintained in `require-dev`; `extra.phar.runtime-packages`
selects the direct roots included in the PHAR. Composer resolves their closure
from exact reviewed lock records in a Git-free staging root with Packagist,
plugins and scripts disabled. The private runtime restores its own PSR-4 loader.
PHPUnit, Prophecy, Rector, ECS and documentation tools are not runtime roots.
PHP Parser is a genuine transitive runtime dependency and remains bundled.

The consumer package no longer exports PHP service classes. A source checkout
with `composer install` exposes those services through `autoload-dev` for
contributor tests and development examples. Existing library integrations must
migrate to the CLI process or remain on their reviewed older library release.
Source remains maintained once, while the artifact contains its compiled copy.

Direct libraries target their latest stable versions compatible with PHP 8.3.
The private Symfony graph now targets 7.4, with security-sensitive components
starting at 7.4.23; consumer Symfony constraints remain independent.
The manifest and lock are authoritative. Security advisory blocking remains on.
Runtime staging retains the diagnostic conflicts for old PHP-DI,
polyfill-php83 and translation-contracts; these exclusions do not constrain the
consumer's independent libraries.

PHP 8.3–8.5 tests qualify the locked source graph on Linux, ARM and Windows.
The component job separately minimizes the supported private 7.4 family. Fresh
Composer fixtures install the CLI without development dependencies, test local
and global proxies and verify isolation from conflicting host constraints.

## Actions and reproducibility

Composite Actions provision PHP 8.3 and extensions, then invoke the same ready
PHAR without Composer or consumer dependencies. Existing reusables retain exact
trusted runtime SHA and consumer checkout boundaries. Docker/image publication
is retired; no interpreter is embedded in the PHAR itself.

Box remains an isolated CI tool, outside the runtime and consumer graph. All
runtime licenses and complete resources are preserved in the executable;
resources also remain outside for skills and orchestration. Stable Git-free
staging avoids embedding the next refresh commit. Composer package metadata
supplies installed version and reference without loading consumer PHP.

Official `symfony/polyfill-php84` and `symfony/polyfill-php85` are explicit runtime
roots for newer array APIs. The native interactive prompt uses Symfony 7.4's
validator callback, preserving blank-answer retries and meaningful Markdown.
