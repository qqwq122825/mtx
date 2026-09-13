<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Security,HomeSettings,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','POST');Security::session($app);
if (!Security::loggedIn($app)) Http::redirect($app->path('/admin/login.php'));
header('Cache-Control: no-store');
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::csrf();
    try { if (Http::text($_POST,'action',20)==='upload') HomeSettings::upload($app,$_POST); else HomeSettings::save($app->store,$_POST); $_SESSION['home_saved']=true; Http::redirect($app->path('/admin/home.php')); }
    catch (Problem $e) { http_response_code($e->status);$error=$e->getMessage(); }
}
$home=HomeSettings::read($app->store);$state=$app->store->read();
$saved=$_SESSION['home_saved']??false;unset($_SESSION['home_saved']);
function e(mixed $v):string { return Http::escape($v); }
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>主页设置 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>?v=admin2"><?php MTX\AdminLayout::assets($app); ?><script src="<?=MTX\Http::escape($app->path('/assets/app.js'))?>?v=admin2" defer></script></head><body>
<?php MTX\AdminLayout::begin($app,'主页与安装器','website'); ?>
<main class="content bot-page"><div class="page-heading"><div><div class="eyebrow">WEBSITE SETTINGS</div><h1>主页与安装器</h1><p class="muted">配置主页的买卡与安装器下载入口，无需修改代码。</p></div><a class="button quiet" href="/" target="_blank" rel="noopener">查看主页 ↗</a></div>
<?php if ($saved): ?><div class="notice success" role="status">主页设置已保存。</div><?php endif ?>
<?php if ($error): ?><div class="notice error" role="alert"><?=e($error)?></div><?php endif ?>
<div class="work-grid home-work-grid"><section class="panel"><div class="section-title"><h2>主页入口</h2></div><form method="post" action="<?=e($app->path('/admin/home.php'))?>" class="bot-form">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="revision" value="<?=e($home['revision'])?>">
<label for="buy-url">买卡跳转地址</label><input id="buy-url" name="buy_url" type="url" maxlength="2048" value="<?=e($home['buy_url'])?>" placeholder="https://">
<label for="download-url">通用安装器下载地址（可选）</label><input id="download-url" name="download_url" type="url" maxlength="2048" value="<?=e($home['download_url'])?>" placeholder="https://你的下载域名/安装器.tipa">
<p class="muted">请填写安装器的公开 HTTPS 下载直链或下载页面，不要填写游戏辅助 TIPA 更新接口、后台地址或带私密凭据的链接。留空会显示暂未开放。主页不会显示后台入口。</p>
<button class="button primary" type="submit">保存主页设置</button></form></section>
<section class="panel"><div class="section-title"><h2>上传安装器</h2></div><p class="muted">只上传编译完成的安装器，不是辅助更新包。每个游戏保留一个当前下载入口；上传新版后自动切换，不删除旧文件。上传上限 <?=e(round($app->config['max_upload_bytes']/1048576))?> MiB。</p>
<form method="post" enctype="multipart/form-data" action="<?=e($app->path('/admin/home.php'))?>" class="bot-form">
<input type="hidden" name="csrf" value="<?=e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="upload">
<label for="installer-game">对应游戏</label><select id="installer-game" name="game_id" required><?php foreach ($state['apps'] as $game): ?><option value="<?=e($game['game_id'])?>"><?=e($game['name'])?> · ID <?=e($game['game_id'])?></option><?php endforeach ?></select>
<label for="installer-file">安装器文件</label><input id="installer-file" name="installer" type="file" accept=".tipa,.ipa" required>
<button class="button primary" type="submit">上传并开放下载</button></form>
</section></div><section class="panel"><div class="section-title"><h2>当前下载列表</h2><a class="text-button" href="/?view=downloads" target="_blank" rel="noopener">查看下载页 ↗</a></div><div class="bot-table-wrap"><table class="bot-table" data-installer-table><thead><tr><th>游戏</th><th>游戏 ID</th><th>包大小</th><th>下载状态</th></tr></thead><tbody><?php foreach ($state['apps'] as $game): $installer=$state['home_installers'][(string)$game['game_id']]??null; ?><tr><td><?=e($game['name'])?></td><td><?=e($game['game_id'])?></td><td><?=$installer?e(number_format($installer['bytes']/1048576,2)).' MiB':'—'?></td><td><?php if ($installer): ?><a href="/installer?game_id=<?=e($game['game_id'])?>">下载安装器</a><?php else: ?>尚未上传<?php endif ?></td></tr><?php endforeach ?></tbody></table></div></section></main><?php MTX\AdminLayout::end(); ?></body></html>
