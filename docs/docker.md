# Docker migration

The compiled CLI replaces this repository's Docker runtime. The six public
Actions keep their paths and inputs, provision PHP and execute the ready-built
`dist/changelog` directly. CI no longer builds or pushes a container image.
No Docker daemon, registry login or consumer Composer installation is required
for these Actions. See [compiled distribution](phar.md) and the
[workflow and Action reference](workflow-reference.md).

Existing published images are not deleted or updated by this change. Integrations
using an older release keep that release's documented image contract. New
integrations should select a reviewed release and use its reusable workflows,
composite Actions or Composer-installed binary.

An application that needs its own container can copy the executable into an image
with the selected release's supported PHP version and extensions. Git operations
also need Git; HTTP operations need their documented backend. The PHAR bundles
PHP libraries and resources, not the PHP interpreter or operating-system tools.
Such an application owns its image build and publication.

[Documentation index](index.md)
