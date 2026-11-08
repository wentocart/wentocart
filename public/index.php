<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

$_SERVER = \App\Http\SubdirectoryFrontController::normalizeServer($_SERVER);

// Apache and php-fpm reuse one process for every site on the machine.
// Another shop's APP_URL, database, and key would otherwise stay in that
// process, and this folder would open the other shop's database. Drop this
// folder's own keys before .env is read so www/1 and www/2 stay separate.
$envFile = __DIR__.'/../.env';
if (is_file($envFile)) {
    $envLines = file($envFile, FILE_IGNORE_NEW_LINES) ?: [];
    foreach ($envLines as $envLine) {
        $envLine = trim($envLine);
        if ($envLine === '' || str_starts_with($envLine, '#')) {
            continue;
        }
        $eq = strpos($envLine, '=');
        if ($eq === false) {
            continue;
        }
        $envName = trim(substr($envLine, 0, $eq));
        if ($envName === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $envName) !== 1) {
            continue;
        }
        putenv($envName);
        unset($_ENV[$envName], $_SERVER[$envName]);
    }
}

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
