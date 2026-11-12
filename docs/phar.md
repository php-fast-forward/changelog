# Standalone PHAR qualification

Issue [#90](https://github.com/php-fast-forward/changelog/issues/90) tracks an
additional standalone CLI distribution. This draft qualifies a Box build; it
does not replace the published Composer package or the six Docker Actions.

## Build and runtime

`box.json` selects the existing entrypoint, complete `src`, `resources` and
production `vendor` trees, Composer metadata and license. Source is maintained
once. The bundled libraries occupy bytes inside the archive as well as any
separate source installation. Box stays outside the dependency graph.

The qualification workflow installs the reviewed production lock with plugins
and scripts disabled, downloads official Box 4.7.1 with its SHA-256 digest, builds
`dist/changelog`, runs Box verification and disposable command tests, then
uploads that exact artifact. It has read-only permissions, no autocommit and no
release operation. Changes to artifact inputs or this packaging surface, or an
explicit dispatch, run this read-only qualification; ordinary documentation
changes do not rebuild it. This does not commit a new binary on every PR.

Local builds measured about 3 MB with GZ and 12.7 MB with NONE. The GZ artifact needs PHP `^8.5`,
Phar and zlib, plus the bundled runtime's platform requirements (currently
mbstring or its bundled iconv-backed polyfill). Git operations still need Git.
Generated-file HTTP uploads require the supported cURL backend. A PHAR supplies
neither PHP, Git nor extensions; it cannot replace their provisioning.

Build manually with an isolated Box executable:

```sh
composer install --no-dev --no-plugins --no-scripts --prefer-dist --classmap-authoritative
php /path/to/box.phar validate --ignore-recommendations-and-warnings box.json
php -d phar.readonly=0 /path/to/box.phar compile --no-restart --no-parallel -c box.json
php /path/to/box.phar verify dist/changelog
php tests/Phar/verify.php
```

The generated Box stub was tested first: command discovery worked, but native
`skill:install --dry-run` failed because its guarded donor inventory requires an
absolute physical path. `stub.php` therefore extracts one private runtime with
mode 0700, selects its bundled autoloader, invokes the existing entrypoint and
removes the runtime at shutdown. It keeps the caller's CWD and arguments. There
is no shared cache or consumer autoload discovery. Forced termination can leave
that invocation's directory behind. The maintained disposable test checks actual
skill bytes, fragments, an explicit project PHP template, Git, exit status and
ordinary shutdown cleanup.

## Composer and API boundary

The existing package is deliberately `type: library`, exposes
`FastForward\\Changelog\\` through PSR-4 and documents in-process services,
callbacks and replaceable boundaries. Retain `require`, `autoload`, `src` and
the current `bin/changelog`. Moving its runtime libraries to `require-dev` or
export-ignoring source would break PHP consumers. A standalone CLI process has
its own dependency universe; a PHAR loaded into another PHP process does not.

There are two distinct publication choices:

1. Keep the library package and add a tested release PHAR. Composer source/API
   consumers keep their existing graph. Pointing that package's `bin` to a
   tracked compiled artifact can later select the standalone CLI, but does not
   remove the library's dependency constraints.
2. If a PHP-only Composer graph is required, define a distinct CLI package
   identity in this same repository, or deliberately withdraw the existing PHP
   API in a reviewed breaking release. This draft activates neither choice.

A GitHub release asset alone does not enter Packagist's default tagged-tree ZIP.
Before changing `composer.json`'s binary path, the selected publication flow must
put the tested artifact in the exact tree delivered to Composer. The new native
generated-file release contract can include an existing tracked artifact in the
same signed version commit as history and fragment deletions. That publication
flow must also bind the intended version and source SHA; this qualification's
`dev-main` metadata is not an official release identity.

## Docker and remaining qualification

All six public Actions use the root Dockerfile, which packages PHP 8.5, Git,
dependencies and the reviewed HTTP backend without requiring the consumer to
install them. Preserve it. A later image can copy the qualified PHAR instead of
installing the source dependency tree. Replacing Docker with a composite Action
would change host/runtime provisioning and must be independently qualified.

Before publication, qualify Composer's local/global proxies against the selected
real package manifest, archives and source preference; callback/autoload/API
coexistence; all six automation operations and HTTP upload/streaming inside the
actual packaged runtime; Windows invocation; minimal extension combinations;
repeat-build byte identity and runtime metadata. The initial test is evidence
for the listed local CLI paths, not proof of every privileged operation or an
already available Packagist binary. No consumer needs CPX or a build step to run
the resulting standalone artifact.

A disposable ZIP fixture with a deliberately different package identity and
`bin: ["dist/changelog"]` passed real `composer require --prefer-dist` and
`composer global require` in private Composer homes. Its package contained the
PHAR, manifests, resources and license, with no external `src` or `vendor` tree;
both generated proxies ran discovery and native skill preview. This proves the
proxy/ZIP route, not that the existing library manifest or Packagist already
ships this alternative. No global host installation was changed.
