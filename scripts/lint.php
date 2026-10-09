<?php

declare(strict_types=1);

/**
 * Development tooling for the standalone Fast Forward Changelog package.
 *
 * This file is part of fast-forward/changelog project.
 *
 * @copyright Copyright (c) 2026 Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @author    Felipe Sayao Lobato Abreu <github@mentordosnerds.com>
 * @license   https://opensource.org/licenses/MIT MIT License
 *
 * @see https://github.com/php-fast-forward/changelog
 */

$phpFiles = require __DIR__ . '/php-files.php';
$failed = false;

foreach ($phpFiles() as $file) {
    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open([PHP_BINARY, '-l', $file], $descriptor, $pipes);

    if (false === $process) {
        fwrite(STDERR, "Unable to start PHP lint for {$file}.\n");
        exit(1);
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (0 !== proc_close($process)) {
        fwrite(STDERR, $output);
        $failed = true;
    }
}

fwrite(
    $failed ? STDERR : STDOUT,
    $failed ? "PHP lint failed.\n" : "Every package PHP file passes syntax validation.\n",
);
exit($failed ? 1 : 0);
