<?php
declare(strict_types=1);
use MTX\{Http,Security,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','POST'); Security::session($app);
if (Security::loggedIn($app)) Http::redirect('/admin/');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try { Security::csrf(); Security::login($app,Http::text($_POST,'password',1024)); Http::redirect('/admin/'); }
    catch (Problem $e) { http_response_code($e->status); $error=$e->getMessage(); }
}
header('Cache-Control: no-store');
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>登录 · 满天星更新中心</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="login-body"><main class="login-shell"><section class="login-story"><a class="brand brand-light" href="/admin/"><span class="brand-mark">✳</span><span>满天星<span class="brand-sub">UPDATE CENTER</span></span></a><div class="story-content"><div class="eyebrow">ONE GAME. ONE ENDPOINT.</div><h1>更新内容，<br>不改变入口。</h1><p>一个游戏，一个固定地址。<br>把新版本交给后台，让安装器拿到正确的包。</p><div class="story-steps"><span>01 上传</span><i></i><span>02 校验</span><i></i><span>03 发布</span></div></div><div class="story-footer">MTX / RELEASE MANAGEMENT <span>v1.0</span></div></section><section class="login-panel"><div class="login-heading"><span class="pill">管理员入口</span><h2>欢迎回来</h2><p class="muted">输入管理密码，继续管理游戏更新。</p></div>
<?php if ($error): ?><div class="notice error" role="alert"><?=Http::escape($error)?></div><?php endif ?>
<form method="post" action="/admin/login.php"><input type="hidden" name="csrf" value="<?=Http::escape($_SESSION['csrf'])?>"><label for="password">管理密码</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="72" placeholder="请输入管理密码" autofocus><button class="button primary login-button" type="submit">进入更新中心 <span>→</span></button></form><p class="login-note">密码由服务器 PHP 配置校验。<br>包文件与版本记录直接存储在服务器目录。</p><div class="login-lock">● 私有后台 · 无数据库</div></section></main></body></html>
