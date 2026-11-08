<?php

/**
 * Print a loopback TCP port the helper should use.
 *
 * Optional argv[1]: saved/chosen Port (helper.port or APP_URL). Always printed
 * even if something is already accepting — relaunch must reopen that shop,
 * not migrate to 8101 because 8024 is busy with this copy.
 *
 * With no preference: first free port in 8099–8199. Probe with connect, not bind
 * (Windows can appear to bind a second php -S on a busy port).
 */
$host = '127.0.0.1';
$prefer = isset($argv[1]) ? (int) $argv[1] : 0;
if ($prefer >= 1 && $prefer <= 65535) {
    echo (string) $prefer;
    exit(0);
}

for ($port = 8099; $port <= 8199; $port++) {
    $errno = 0;
    $errstr = '';
    $probe = @fsockopen($host, $port, $errno, $errstr, 0.25);
    if (is_resource($probe)) {
        fclose($probe);
        continue;
    }
    echo (string) $port;
    exit(0);
}

fwrite(STDERR, "No free port between 8099 and 8199.\n");
exit(1);
