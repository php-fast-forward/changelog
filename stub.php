#!/usr/bin/env php
<?php

declare(strict_types=1);

// The native skill installer requires a physical donor tree with guarded ancestry.
Phar::mapPhar();
$runtime = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
    . '/fast-forward-changelog-' . bin2hex(random_bytes(16));
if (!mkdir($runtime, 0700)) {
    fwrite(STDERR, "Could not create the PHAR runtime directory.\n");
    exit(1);
}
register_shutdown_function(static function () use ($runtime): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isDir() && !$file->isLink()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($runtime);
});
new Phar(__FILE__)->extractTo($runtime);
$_composer_autoload_path = $runtime . '/vendor/autoload.php';
require $runtime . '/bin/changelog';
__HALT_COMPILER();
