<?php

/*
 * Web entry point on shared hosting (copied to public_html/index.php by deploy/agent.php).
 * The application lives in ./_app; bootstrap/app.php switches to the hosting layout.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/_app_storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/_app/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/_app/bootstrap/app.php';

$app->handleRequest(Request::capture());
