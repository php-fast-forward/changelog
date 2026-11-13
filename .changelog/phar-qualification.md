---
category: added
type: minor
---

Distributes the ready-built Changelog PHAR through local and global Composer installations, embedding all execution libraries and complete resources. Consumers require PHP 8.3 and the declared extensions, without resolving library dependencies or compiling the CLI.

Replaces the Composer PHP-library contract with a compiled CLI: runtime libraries move to development dependencies and service namespaces are no longer autoloaded for consumers. Source development remains available through autoload-dev; resources and skills remain exported for orchestration.

Updates runtime and contributor dependencies to current stable PHP 8.3-compatible releases, including patched Symfony 7.4 and official PHP 8.4/8.5 polyfills. Consolidates the superseded standalone dependency-update fragment into this delivery.

Replaces Docker-backed Actions with PHP-provisioned composite Actions preserving inputs, outputs, quoted arguments and trusted runtime revisions. Retires image builds and publication without changing existing remote images or credentials. CI builds and qualifies one executable before its guarded refresh or native version transaction; the minimal extraction stub lives beside build preparation.
