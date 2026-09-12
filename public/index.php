<?php
declare(strict_types=1);
define('MTX_FRONT_CONTROLLER', true);
$app = require dirname(__DIR__).'/src/bootstrap.php';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$mount = $app->config['mount_path'];
$notFound = static function (): never { http_response_code(404); header('Cache-Control: no-store'); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; exit; };
if ($path === $mount || $path === $mount.'/') MTX\Http::redirect($app->path('/admin/'));
if (!str_starts_with($path, $mount.'/')) $notFound();
$route = substr($path, strlen($mount));
$GLOBALS['mtx_route'] = $route;
$routes = ['/admin'=>'admin/index.php','/admin/'=>'admin/index.php','/admin/index.php'=>'admin/index.php','/admin/login.php'=>'admin/login.php','/admin/action.php'=>'admin/action.php','/api/update.php'=>'api/update.php','/api/download-ticket.php'=>'api/download-ticket.php','/api/download.php'=>'api/download.php'];
if (in_array($route, ['/assets/app.css','/assets/app.js'], true)) {
    MTX\Http::method('GET','HEAD');
    header('Content-Type: '.(str_ends_with($route,'.css')?'text/css':'text/javascript').'; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') readfile(__DIR__.$route);
    exit;
}
if (!isset($routes[$route])) $notFound();
require __DIR__.'/'.$routes[$route];
