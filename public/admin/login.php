<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Security,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','POST'); Security::session($app);
if (Security::loggedIn($app)) Http::redirect($app->path('/admin/'));
$error='';
$remember=$_SERVER['REQUEST_METHOD']==='GET' || ($_POST['remember']??'')==='1';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try { Security::csrf(); Security::login($app,Http::text($_POST,'password',1024),$remember); Http::redirect($app->path('/admin/')); }
    catch (Problem $e) { http_response_code($e->status); $error=$e->getMessage(); }
}
header('Cache-Control: no-store');
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · 满天星更新中心</title><link rel="stylesheet" href="<?=MTX\Http::escape($app->path('/assets/app.css'))?>?v=admin2"></head>
<body class="login-body admin-login"><main class="admin-login-card"><a class="admin-login-brand" href="/"><span>✳</span>满天星</a><header><h1>登录管理控制台</h1><p class="muted">管理游戏更新、安装器与 Telegram 客服</p></header>
<?php if ($error): ?><div class="notice error" role="alert"><?=Http::escape($error)?></div><?php endif ?>
<form method="post" action="<?=Http::escape($app->path('/admin/login.php'))?>"><input type="hidden" name="csrf" value="<?=Http::escape($_SESSION['csrf'])?>"><label for="password">管理密码</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72" placeholder="请输入管理密码" autofocus><label class="checkbox login-remember"><input type="checkbox" name="remember" value="1" <?=$remember?'checked':''?>>在此设备上记住登录 30 天</label><button class="button primary login-button" type="submit">登录</button></form><p class="admin-login-note">仅需密码，无需用户名。<br>公共设备请取消记住登录，使用后退出。</p></main></body></html>
