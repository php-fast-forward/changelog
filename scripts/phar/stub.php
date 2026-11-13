#!/usr/bin/env php
<?php

declare(strict_types=1);

// The native skill installer requires a physical donor tree with guarded ancestry.
Phar::mapPhar();
$pharPackageMetadata = [];
if (isset($_composer_autoload_path) && is_string($_composer_autoload_path)) {
    $installedJson = dirname($_composer_autoload_path) . '/composer/installed.json';
    if (is_file($installedJson)) {
        $installed = json_decode(file_get_contents($installedJson), true, flags: JSON_THROW_ON_ERROR);
        foreach ($installed['packages'] ?? [] as $package) {
            if ('fast-forward/changelog' === ($package['name'] ?? null)) {
                $pharPackageMetadata = [
                    'version' => $package['version'],
                    'reference' => $package['source']['reference'] ?? $package['dist']['reference'] ?? null,
                ];

                break;
            }
        }
    }
}
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
(new Phar(__FILE__))->extractTo($runtime);
$_composer_autoload_path = $runtime . '/vendor/autoload.php';
require $runtime . '/bin/changelog';
__HALT_COMPILER();
