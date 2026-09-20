<?php
declare(strict_types=1);
define('MTX_FRONT_CONTROLLER', true);
$app = require dirname(__DIR__).'/src/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mount = $app->config['mount_path'];
$notFound = static function (): never { http_response_code(404); header('Cache-Control: no-store'); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; exit; };
if ($path==='/') { require dirname(__DIR__).'/src/views/home.php'; exit; }
if ($path==='/installer') { require dirname(__DIR__).'/src/views/installer-download.php'; exit; }
if ($path==='/home.css') {
    MTX\Http::method('GET','HEAD');header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    if ($_SERVER['REQUEST_METHOD']==='GET') readfile(__DIR__.'/assets/home.css');
    exit;
}
// The API namespace is stable across admin-folder renames. Its root never reveals the admin URL.
if (str_starts_with($path,$mount.'/')) {
    $route=substr($path,strlen($mount));$GLOBALS['mtx_route']=$route;
    $routes=['/api/telegram-webhook.php'=>'api/telegram-webhook.php','/api/update.php'=>'api/update.php','/api/download-ticket.php'=>'api/download-ticket.php','/api/download.php'=>'api/download.php'];
    if (in_array($route,['/assets/app.css','/assets/app.js','/assets/telegram-announcements.js'],true)) {
        MTX\Http::method('GET','HEAD');
        header('Content-Type: '.(str_ends_with($route,'.css')?'text/css':'text/javascript').'; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        if ($_SERVER['REQUEST_METHOD']==='GET') readfile(__DIR__.$route);
        exit;
    }
    if (!isset($routes[$route])) $notFound();
    require __DIR__.'/'.$routes[$route];exit;
}
if (!preg_match('~\A/([A-Za-z0-9][A-Za-z0-9_-]{0,63})(?:/(.*))?\z~',$path,$parts)) $notFound();
$folder=$parts[1];$file=$parts[2]??'';
$adminFiles=[''=>'index.php','index.php'=>'index.php','home.php'=>'home.php','login.php'=>'login.php','action.php'=>'action.php','telegram.php'=>'telegram.php','telegram-announcements.php'=>'telegram-announcements.php'];
if (!isset($adminFiles[$file])) $notFound();
// Unknown URLs get a plain 404, even if the configured admin directory is missing.
$marker=__DIR__.'/'.$folder.'/.mtx-admin';
if (is_link(__DIR__.'/'.$folder) || is_link($marker) || !is_file($marker)) $notFound();
if ($folder!==$app->adminDirectory()) $notFound();
$GLOBALS['mtx_route']='/admin/'.$file;
$handler=__DIR__.'/'.$app->adminDirectory().'/'.$adminFiles[$file];
if (is_link($handler) || !is_file($handler)) $notFound();
require $handler;
