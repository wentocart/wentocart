#!/usr/bin/env php
<?php

/**
 * Command-line installer. Safe to run with an older PHP on PATH — it re-execs 8.3+.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this from a terminal: php scripts/install/cli.php\n");
    exit(1);
}

require __DIR__.'/helper.php';
require __DIR__.'/Runtime.php';

$root = dirname(__DIR__, 2);
exit((new Wento\Install\Runtime($root))->run($argv));
