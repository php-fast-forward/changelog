---
category: added
type: minor
---

Ships the compiled CLI through Composer and maintains its tested PHAR with the existing CI refresh pattern, while preserving the public PHP API and Docker Actions. Local and global usage no longer requires a separate command executor; the compressed CLI requires Phar and zlib alongside PHP 8.5.
