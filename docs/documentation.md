# Building The Documentation

The documentation combines the Markdown guides in `docs/` with the PHP API
reference generated from `src/`. `phpdoc.dist.xml` selects those sources and the
Fast Forward Bootstrap template. The rendered homepage is `docs/index.md`.
`docs/index.rst` points source readers to that canonical homepage; this Markdown
build does not read it.

## Local Build

Use PHP 8.5, Composer and GnuPG. Install the locked development dependencies
from the repository root:

```sh
documentation_composer_home=$(mktemp -d)

COMPOSER_HOME="$documentation_composer_home" composer install \
  --no-interaction --prefer-dist --no-scripts
composer --no-plugins docs:build
```

The project's Composer configuration permits only `phpdocumentor/shim` as a
plugin. It downloads the phpDocumentor PHAR corresponding to its locked version
and verifies its signature with GnuPG. The PHAR isolates phpDocumentor's Symfony
dependencies from the library's Symfony 8 runtime. The Fast Forward template is
a separate development dependency. Neither tool is required by consumers.

The temporary Composer home keeps global plugins outside tool installation.
Rendering then disables plugins: the already verified PHAR needs no Composer
plugin at execution time. This prevents an unrelated global plugin from loading
against the project's development dependencies before the documentation command
starts; it does not disable the signature verification needed during installation.

An installation with `--no-plugins` intentionally omits the PHAR. If development
dependencies were installed that way, prepare that one tool explicitly:

```sh
COMPOSER_HOME="$documentation_composer_home" composer reinstall phpdocumentor/shim \
  --no-interaction --prefer-dist --no-scripts
composer --no-plugins docs:build
```

The generated site is `.build/docs/index.html`; generated PHP source pages,
API pages, guides and template assets stay under `.build/docs/`. The parser
cache is `.build/phpdoc-cache/`. Both paths are ignored by Git. Serve the output
with a local HTTP server to inspect navigation and assets:

```sh
php -S 127.0.0.1:8080 -t .build/docs
```

`composer phpdoc` remains the separate source PHPDoc contract check. It does not
render the site. A successful documentation build also does not replace unit,
integration, packaging or coverage checks.

## Method DocBlocks

Write a short action summary followed by the context a reader needs: invariants,
side effects and observable failures. Document each argument with `@param`, its
type, the exact parameter name and what the value represents. Add `@return` with
a type and an explanation of the result, including `void` for operations without
a result. Constructors and destructors need no return tag. Describe relevant
exceptions with `@throws` when the implementation establishes that contract.

The source gate parses method signatures and PHPDoc syntax, including multiline
array shapes, generics and callable types. It rejects missing, duplicate,
unknown or undescribed argument tags and missing or undescribed return tags.
An `@see` reference cannot replace an argument or result explanation. Review
still establishes semantic accuracy; a syntactically valid comment can describe
the wrong behavior. phpDocumentor uses those summaries and tags to render the
class and method reference alongside the guides.

## CI And Publication

`repository-documentation.yml` installs the locked development graph, executes
`composer --no-plugins docs:build`, checks that the homepage and API were generated,
and uploads the complete site as a workflow artifact. PR builds have read-only
repository permissions and do not receive publication credentials.

The workflow builds on pull requests, pushes to `main` and manual dispatches.
It does not publish GitHub Pages or synchronize a Wiki. Those are separate
operations: the sources, phpDocumentor configuration and build command can be
consumed by future documentation orchestration without changing the guides.
Download the workflow artifact to review the actual output before enabling a
publication route.

## Source To Document Map

| Source | Documentation |
| --- | --- |
| `.github/workflows/changelog*.yml`, `.github/actions/`, `src/Automation/` | `reusable-lifecycle.md`, `github-actions.md`, `github-app.md`, `policies.md`, `automation-map.md` |
| `src/Console/`, `src/Fragment/`, `src/Changeset/` | `adoption.md`, `cli-fragments.md`, `specification/fragment-format.md` |
| `src/Release/`, `src/Publication/` and their tests | `release-receipts.md`, `publication.md`, `version-pull-request.md` |
| `src/Version/` and versioning tests | `specification/versioning.md` |
| `src/History/`, `src/Template/` | `history.md` |
| `src/Container/`, `composer.json`, `Dockerfile` | `dependencies.md`, `docker.md`, `source-layout.md` |
| `resources/skills/fast-forward-changelog/`, including its executable contribution recipe | `adoption.md`, `skills.md`, `composer-skills.md` |
| All `src/` declarations and PHPDoc | Generated namespace, class and method reference |

Keep each executable example in one canonical source and link to it from guides
and navigation. When changing a public contract, update its guide and PHPDoc together, then inspect
the rendered result. The generated site supplements the source guides; it is
not another set of hand-maintained copies.
