<?php

if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    if (! headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Robots-Tag: noindex, nofollow', true);
    }

    $currentVersion = htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8');
    echo '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>KaevCMS — PHP version</title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4efe7;color:#28231f;font:16px/1.55 system-ui,-apple-system,"Segoe UI",sans-serif}.card{width:min(720px,calc(100% - 32px));padding:36px;border:1px solid #e5d9cc;border-radius:24px;background:#fffdf9;box-shadow:0 20px 70px rgba(89,67,42,.12)}h1{margin:0 0 16px;font:700 34px/1.15 Georgia,serif}p{margin:10px 0;color:#665e56}code{padding:2px 7px;border-radius:7px;background:#f3ebe1;color:#7b4d30}.en{margin-top:28px;padding-top:24px;border-top:1px solid #e5d9cc}</style></head><body><main class="card"><h1>Требуется PHP 8.3 или новее</h1><p>На сервере сейчас используется PHP <code>'.$currentVersion.'</code>. Измените версию PHP для домена в панели управления хостингом, затем обновите страницу.</p><p>KaevCMS не запускает установщик на неподдерживаемой версии PHP, чтобы не показывать пустую страницу или необработанную ошибку.</p><section class="en" lang="en"><h1>PHP 8.3 or newer is required</h1><p>The server currently uses PHP <code>'.$currentVersion.'</code>. Change the PHP version for this domain in the hosting control panel, then reload this page.</p><p>KaevCMS does not start the installer on an unsupported PHP version, preventing a blank page or an unhandled parse error.</p></section></main></body></html>';
    exit;
}

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$pathFile = __DIR__.'/kaevcms-path.php';
$sharedHosting = is_file($pathFile);
$projectRoot = $sharedHosting ? require $pathFile : dirname(__DIR__);

if (! is_string($projectRoot) || ! is_file($projectRoot.'/bootstrap/app.php')) {
    http_response_code(500);
    echo $sharedHosting
        ? 'KaevCMS core directory could not be found.'
        : 'KaevCMS application directory could not be found.';
    exit;
}

if (! is_file($projectRoot.'/.env')) {
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
    $scriptDirectory = str_replace('\\', '/', dirname($scriptName));
    $basePath = $scriptDirectory === '/' ? '' : rtrim($scriptDirectory, '/');
    header('Location: '.$basePath.'/install/', true, 302);
    exit;
}

$maintenance = $projectRoot.'/storage/framework/maintenance.php';
if (file_exists($maintenance)) {
    require $maintenance;
}

require $projectRoot.'/vendor/autoload.php';
$application = require $projectRoot.'/bootstrap/app.php';
if ($sharedHosting) {
    $application->usePublicPath(__DIR__);
}
$application->handleRequest(Request::capture());
