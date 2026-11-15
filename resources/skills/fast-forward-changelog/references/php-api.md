# Development-source PHP services

The current Composer package distributes the compiled CLI and does not autoload
PHP service classes into the consumer. Use `vendor/bin/changelog` in applications.
The example below requires a source checkout after `composer install` with
development dependencies. Older releases retain their own library contracts;
inspect the selected release before adopting an integration.

## Bundled contribution example

The complete [contribution program](../examples/contribution.php) illustrates the
`0.1.0` PHP API: explicit source development autoloader and target directory, provider
composition, fragment creation and validation. It is an optional example, not
the contract for every later release. Before using it with another version,
compare its imports/signatures with that release's documented API or use the
example shipped with the selected release.

From a matching development checkout, run it first in a disposable target:

```sh
php .agents/skills/fast-forward-changelog/examples/contribution.php ./vendor/autoload.php /absolute/project
vendor/bin/changelog check --cwd=/absolute/project --no-interaction
```

Success prints `Contribution example PASS`; exactly one
`.changelog/php-api-example.md` is created, with no commit or central-history
change. A repeated run must expose the filename collision rather than overwrite
the file. Missing inputs and runtime failures remain nonzero. Do not report
success after a failed write or ignore the validation report.

Keep PR/issue/author fields absent unless verified. Service access follows the
same fragment, version-transaction and publication boundaries as the selected
release's CLI. Learn optional configuration, planner/history services and
approved-commit publication from that version's documentation when the task
requires them; do not load an API inventory for an ordinary fragment task.
