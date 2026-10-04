Fast Forward Changelog
======================

``fast-forward/changelog`` records independent Markdown fragments, consolidates
them through a reviewed version PR and publishes exact notes from its approved
merge commit. The runtime requires PHP 8.5 and Composer.

Install and author
------------------

Install a release or reviewed revision containing the current command surface:

.. code-block:: bash

   composer require --dev fast-forward/changelog
   vendor/bin/changelog list --raw
   vendor/bin/changelog add "Preserves meaningful Markdown spaces." --category=fixed --no-interaction
   vendor/bin/changelog check --no-interaction
   vendor/bin/changelog check --since=origin/main --no-interaction

Select or fetch the intended baseline before the last check. A global Composer
installation exposes the same commands as ``changelog``. Both forms use the
consumer working directory. No initialization or local configuration is needed;
authoring creates one unique file in ``.changelog/`` and leaves ``CHANGELOG.md``
for the version workflow. Metadata is optional and commits are opt-in.

Read `the adoption guide <adoption.md>`_,
`fragment authoring <cli-fragments.md>`_ and
`the fragment schema <specification/fragment-format.md>`_.

Command surface
---------------

The eight public commands are ``add``, ``check``, ``status``, ``version``,
``notes``, ``publish``, ``backfill`` and ``format``. Inspect their individual help
through ``vendor/bin/changelog help <command>``.

.. code-block:: bash

   vendor/bin/changelog status --json --source=tags
   vendor/bin/changelog version --dry-run --source=tags
   vendor/bin/changelog backfill --dry-run --source=tags
   vendor/bin/changelog format --dry-run --locale=pt-BR
   vendor/bin/changelog notes 1.2.3

Status and dry runs inspect plans without managed writes. Applying version or
history changes is an explicit maintainer operation. Ordinary contribution work
adds and checks a fragment, then the version workflow opens a separate PR.
Publication requires the approved complete SHA and validates committed evidence.

Read `history and presentation <history.md>`_,
`release transactions and local recovery <release-receipts.md>`_ and
`publication <publication.md>`_.

Automation and self-hosting
--------------------------

Composite actions and reusable workflows expose check, Dependabot, version,
publication and history operations. Consumers select a reviewed immutable
revision, keep ordinary PR checks read-only and execute write operations from
trusted base code. PR labels and bot-looking identities do not establish approval.

This repository uses its own CLI and local actions for those workflows. Read
`the self-hosting examples <self-changelog.md>`_,
`GitHub action contracts <github-actions.md>`_,
`version PRs <version-pull-request.md>`_ and `verified policy <policies.md>`_.

PHP composition and verification
--------------------------------

``ChangelogServiceProvider`` integrates with ``fast-forward/container`` and
supplies a Symfony Console lazy command loader. Listing commands and general
help do not construct selected command dependencies; a failure is isolated to
the command that uses it. Services receive filesystem, Git, HTTP, options,
factory and PSR-20 clock collaborators through interfaces.

The unit suite replaces I/O with doubles and requires 100% production line
coverage. Real Composer installations and disposable consumer Git repositories
belong to the separate `packaging harness <../tests/Packaging/README.md>`_. Read
`the foundation map <foundation.md>`_ for reusable quality gates,
`dependency contracts <dependencies.md>`_ and
`skill distribution <skills.md>`_ for installation boundaries.
