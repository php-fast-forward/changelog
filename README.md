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
- 🔌 Stay embeddable so larger CLIs can register the commands directly

## 📦 Installation

```bash
composer require fast-forward/changelog
```

Requirements:

- PHP `8.3+`
- Symfony Console, Filesystem, and Process components

## 🛠️ Usage

Run the standalone CLI:

```bash
changelog list
changelog changelog:entry "Add release automation"
changelog changelog:resolve-version
changelog changelog:render-release-notes 1.2.0
```

Register the commands inside another Symfony Console application:

```php
use DI\Container;
use FastForward\Changelog\Console\Command\ChangelogEntryCommand;
use FastForward\Changelog\Console\Command\ChangelogPromoteCommand;
use FastForward\Changelog\Console\Command\ChangelogReleaseNotesRenderCommand;
use FastForward\Changelog\Console\Command\ChangelogVersionResolveCommand;
use Symfony\Component\Console\Application;

$container = new Container();
$application = new Application('My Tooling');

$application->add($container->get(ChangelogEntryCommand::class));
$application->add($container->get(ChangelogPromoteCommand::class));
$application->add($container->get(ChangelogVersionResolveCommand::class));
$application->add($container->get(ChangelogReleaseNotesRenderCommand::class));
```

## 🧰 API Summary

| Class | Responsibility |
|-------|----------------|
| `ChangelogManager` | Load, mutate, promote, infer, and render changelog releases |
| `ChangelogParser` | Parse Markdown into the managed changelog document model |
| `MarkdownRenderer` | Render deterministic changelog Markdown and release-note bodies |
| `ChangelogDocument` / `ChangelogRelease` | Immutable document model for release sections |

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
  Document/
  Entry/
  Filesystem/
  Git/
  Manager/
  Parser/
  Renderer/
tests/
```

## 🛡 License

MIT © 2026 Felipe Sayao Lobato Abreu

## 🤝 Contributing

Issues and pull requests are welcome. Run `composer validate --strict` and `vendor/bin/phpunit` before opening a PR.

## 🔗 Links

- [Repository](https://github.com/php-fast-forward/changelog)
- [Issues](https://github.com/php-fast-forward/changelog/issues)
- [Packagist](https://packagist.org/packages/fast-forward/changelog)
- [Documentation](https://php-fast-forward.github.io/changelog/)
- [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
- [Semantic Versioning](https://semver.org/spec/v2.0.0.html)
