# Requirements and dependency compatibility

Composer consumers receive the ready-built CLI, not its library dependency graph.
`require` declares PHP `^8.5`, Phar, zlib, iconv, JSON and tokenizer. The bundled
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

Symfony Console requires `^8.1` for native interactive constraints. The four
security-sensitive filesystem, HTTP, validator and YAML components require
`^7.4.23 || ^8.1.11`. Other components retain their declared compatible ranges.
The manifest and lock are authoritative. Security advisory blocking remains on.
Runtime staging retains the diagnostic conflicts for old PHP-DI,
polyfill-php83 and translation-contracts; these exclusions do not constrain the
consumer's independent libraries.

Native PHP 8.5 tests qualify the locked source graph on Linux, ARM and Windows.
Component jobs separately minimize compatible 7.4 and 8.1 families. Fresh
Composer fixtures install the CLI without development dependencies, test local
and global proxies and verify isolation from conflicting host constraints.

## Docker and reproducibility

Docker copies the same ready-built executable and supplies PHP and Git. It does
not resolve Composer dependencies or rebuild the PHAR. The existing
`COMPOSER_ROOT_VERSION` build argument becomes container identity metadata;
that value is not publication authority. Composer proxy metadata takes precedence.

Box is provisioned as an isolated CI tool, outside both the private runtime and
consumer graph. All runtime licenses and complete resources are preserved in
the executable; resources also remain outside it for skills and orchestration.
The stable Git-free build avoids embedding its own subsequent refresh commit.
Versions consumed through Composer derive from package metadata and Git tags.

[Documentation index](index.md)
