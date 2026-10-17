# Injected PHP services

Load when the application needs programmatic access beyond the CLI or workflows.
Supports `fast-forward/changelog ^1.0` (minimum `1.0.0`), PHP `^8.5` and its
Composer-resolved runtime dependencies. Inspect the installed package first.
Keep the application's existing command-loader arrangement; use domain services
without replacing it. The application does not need another loader or framework
to access these services.

## Minimal application route

The package autoloads `FastForward\Changelog\` through the consumer's
`vendor/autoload.php`. Register
`FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider`
by class name with `FastForward\Container\container()`. It has no constructor
arguments. The provider supplies injected filesystem/Git/network/clock boundaries;
resolving a writer does not publish or commit anything.

Run the complete [contribution program](../examples/contribution.php) from the
consumer project, supplying its autoloader and an existing target directory:

```sh
php .agents/skills/fast-forward-changelog/examples/contribution.php ./vendor/autoload.php /absolute/project
vendor/bin/changelog check --no-interaction
```

The program imports the provider, `ReleaseOptionsFactoryInterface`,
`FragmentWriterInterface` and `CheckServiceInterface`. It composes the container,
creates explicit options, writes `.changelog/php-api-example.md`, checks its exact
bytes and validates pending notes. Success prints `Contribution example PASS`.
It never commits or changes central history. Run it in a disposable project
first; a second run deliberately fails on the existing filename without
replacing its bytes. An invalid/missing target or autoloader exits `2`; service
or validation failures remain nonzero with diagnostics.

For an existing application, inject those same interfaces from its PSR-11
container or reuse the provider in its existing composition. The caller supplies
the working directory to the options factory; explicit values override its
built-in defaults. Unknown keys, unsafe managed paths and malformed values throw
`InvalidArgumentException`. Writer schema errors throw `InvalidArgumentException`;
I/O and filename collisions throw `RuntimeException`. Do not catch a failed write
and report success. `CheckServiceInterface::check()` returns a
`ValidationReport` whose `isValid()` must be checked.

## Optional application configuration

The provider accepts `FastForward\Config\config()` initializers in either
order with the provider. For example:

```php
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use function FastForward\Config\config;
use function FastForward\Container\container;

$container = container(
    config(['changelog' => ['working_directory' => '/absolute/project']]),
    ChangelogServiceProvider::class,
);
```

Raw `changelog.*` values are exposed as `config.changelog.*`; the provider
captures them lazily in shared `changelog.config`. Explicit entries, including
empty tokens or nullable values, override defaults. Compose the complete settings
before resolving services; creating another wrapper does not promise a fresh
service graph. Preserve the application's existing container precedence policy.

| Raw key | Default/use |
| --- | --- |
| `working_directory` | Process cwd for CLI composition and package paths. |
| `temporary_directory` | System temp for private recovery journals. |
| `installed_version` | `null`, development fallback. |
| `token` | Empty; application explicitly injects authorized credentials. |
| `api_url` | `https://api.github.com`. |
| `github_output_file` | `null`; no runner output file. |

Provider defaults do not inspect environment credentials or execute project PHP
configuration files. Resolving an unavailable process cwd raises a diagnostic.
Do not use the provider's cwd setting as an implicit options override: pass
`workingDirectory` to `ReleaseOptionsFactoryInterface::create()` as the example
does. Optional templates are executable PHP and require explicit trust.

Additional services include `ReleasePlannerInterface::plan()` for read-only
plans, `ReleaseApplierInterface::apply()` for an authorized exact transaction,
`HistoryCodecInterface` for Markdown parsing/rendering and
`PublicationServiceInterface::publish()` for an approved full SHA. Use their
installed namespaces/signatures and the CLI reference's release invariants;
service access does not grant release authority. Keep external boundaries
injected in application tests and use disposable data for actual adapters.
