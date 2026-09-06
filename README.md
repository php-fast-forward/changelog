# Fast Forward Changelog

Standalone changelog domain and CLI runtime for Fast Forward PHP packages.

[![PHP Version](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](https://www.php.net/releases/)
[![Composer Package](https://img.shields.io/badge/composer-fast--forward%2Fchangelog-F28D1A.svg?logo=composer&logoColor=white)](https://packagist.org/packages/fast-forward/changelog)
[![License](https://img.shields.io/github/license/php-fast-forward/changelog?color=64748B)](LICENSE)
[![GitHub Sponsors](https://img.shields.io/github/sponsors/php-fast-forward?logo=githubsponsors&logoColor=white&color=EC4899)](https://github.com/sponsors/php-fast-forward)

## ✨ Features

- 📘 Parse and render Keep a Changelog 1.1.0 documents deterministically
- 🛠️ Manage changelog entries and promote `Unreleased` into published releases
- 🚀 Expose reusable Symfony Console commands for release automation
- ✅ Check whether `Unreleased` contains a change relative to a Git baseline
- 🔌 Compose services through `fast-forward/container` and load commands lazily

## 📦 Installation

```bash
composer require fast-forward/changelog
```

Requirements:

- PHP `8.3+`
- Symfony Console, Filesystem, and Process components
- `fast-forward/container` and a PSR-20 clock supplied by `fast-forward/clock`

## 🛠️ Usage

Run the standalone CLI:

```bash
changelog list
changelog changelog:entry "Add release automation"
changelog changelog:check --ref=origin/main
changelog changelog:resolve-version
changelog changelog:promote 1.2.0
changelog changelog:render-release-notes 1.2.0
```

The compatibility aliases `changelog:next-version`, `changelog:show`, and
`changelog:release-notes` resolve to the same lazy commands.

Register the package command loader inside another Symfony Console application:

```php
use FastForward\Changelog\Container\ServiceProvider\ChangelogServiceProvider;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Application;
use function FastForward\Container\container;

$container = container(ChangelogServiceProvider::class);
$application = new Application('My Tooling');
$application->setCommandLoader($container->get(CommandLoaderInterface::class));
```

The loader exposes command metadata without constructing command services. A
misconfigured dependency therefore fails only when its command is executed and
does not prevent the CLI from listing or running unrelated commands.

## 🧰 API Summary

| Class | Responsibility |
|-------|----------------|
| `ChangelogManager` | Load, mutate, promote, infer, and render changelog releases |
| `ChangelogParser` | Parse Markdown into the managed changelog document model |
| `MarkdownRenderer` | Render deterministic changelog Markdown and release-note bodies |
| `ChangelogDocument` / `ChangelogRelease` | Immutable document model for release sections |
| `UnreleasedEntryChecker` | Compare current entries with an optional Git baseline |
| `ChangelogServiceProvider` | Register interfaces, factories, the clock, and lazy commands |

## 🔌 Integration

This package is designed to be shared by:

- `fast-forward/dev-tools` as an aggregator of reusable CLI domains
- `fast-forward/github-actions` as a source of changelog-aware workflow commands
- standalone package repositories that want deterministic changelog automation

## 📁 Directory Structure

```text
bin/
docs/
src/
  Console/
  Container/
  Checker/
  Document/
  Entry/
  Filesystem/
  Git/
  Manager/
  Parser/
  Renderer/
  Version/
tests/
```

## 🛡 License

MIT © 2026 Felipe Sayao Lobato Abreu

## 🤝 Contributing

Issues and pull requests are welcome. Run `composer validate --strict` and
`composer test:coverage` before opening a PR. The unit suite requires 100%
source-line coverage and replaces filesystem, process, clock, and container
collaborators with test doubles.

## 🔗 Links

- [Repository](https://github.com/php-fast-forward/changelog)
- [Issues](https://github.com/php-fast-forward/changelog/issues)
- [Packagist](https://packagist.org/packages/fast-forward/changelog)
- [Documentation](https://php-fast-forward.github.io/changelog/)
- [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
- [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
