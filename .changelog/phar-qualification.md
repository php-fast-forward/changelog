---
category: added
type: minor
---

Ships the compiled CLI through Composer and maintains its tested PHAR with the existing CI refresh pattern, with all execution dependencies embedded and the existing Docker Action interfaces. Local and global usage no longer requires a separate command executor; the compressed CLI requires Phar and zlib alongside PHP 8.5.

Changes the Composer package from a PHP library to a compiled CLI: runtime libraries move to development dependencies and service namespaces are no longer autoloaded for consumers. Source service development remains available through autoload-dev. Consumer dependency graphs contain PHP and required extensions only.
