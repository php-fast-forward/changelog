Fast Forward Changelog
======================

``fast-forward/changelog`` provides the standalone changelog domain and CLI
runtime used by Fast Forward PHP packages.

Installation
------------

.. code-block:: bash

   composer require fast-forward/changelog

Standalone CLI
--------------

.. code-block:: bash

   changelog changelog:entry "Add release automation"
   changelog changelog:check --ref=origin/main
   changelog changelog:resolve-version
   changelog changelog:promote 1.2.0
   changelog changelog:render-release-notes 1.2.0

Embedded Commands
-----------------

The package exposes a ``ChangelogServiceProvider`` for
``fast-forward/container``. Its command loader returns Symfony ``LazyCommand``
wrappers, so listing commands does not construct every command dependency. A
dependency error is isolated to the command that uses it.

The ``changelog:next-version``, ``changelog:show``, and
``changelog:release-notes`` aliases preserve the earlier command surface.

Deterministic time
------------------

Commands obtain the current date through the PSR-20 ``ClockInterface`` binding,
implemented by ``fast-forward/clock``. Applications and unit tests can replace
that binding without changing command code.
