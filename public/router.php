<?php
// Development router: only named entry points and packaged assets are exposed.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)?:'/');
if (in_array($path,['/assets/app.css','/assets/app.js'],true)) return false;
$routes=['/'=>'index.php','/admin'=>'admin/index.php','/admin/'=>'admin/index.php','/admin/index.php'=>'admin/index.php','/admin/login.php'=>'admin/login.php','/admin/action.php'=>'admin/action.php','/api/update.php'=>'api/update.php','/api/download-ticket.php'=>'api/download-ticket.php','/api/download.php'=>'api/download.php'];
if (!isset($routes[$path])) { http_response_code(404); header('Content-Type: text/plain; charset=utf-8'); echo 'Not found'; return; }
require __DIR__.'/'.$routes[$path];
