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
   changelog changelog:resolve-version
   changelog changelog:render-release-notes 1.2.0

Embedded Commands
-----------------

The package also exposes reusable Symfony Console commands so larger tooling
applications can register them directly inside local runtimes such as
``fast-forward/dev-tools`` and ``fast-forward/github-actions``.
