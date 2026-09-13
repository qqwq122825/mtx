<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
MTX\Http::method('GET','HEAD');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
$home=MTX\HomeSettings::read($app->store);$state=$app->store->read();
$downloads=($_GET['view']??'')==='downloads';
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><title><?=$downloads?'安装器下载':'满天星 · 软件服务入口'?></title><link rel="stylesheet" href="/home.css?v=2"></head><body>
<main class="shell"><section class="hero">
<span class="badge">MTX 软件服务入口</span>
<h1><?=$downloads?'安装器下载':'软件下载主页'?></h1>
<?php if ($downloads): ?><p class="intro">选择对应游戏，下载专属安装器。</p><?php endif ?>
<?php if (!$downloads): ?>
<nav class="actions" aria-label="主要入口">
<?php if ($home['buy_url']!==''): ?><a class="button primary" href="<?=MTX\Http::escape($home['buy_url'])?>" rel="noopener noreferrer">购买</a><?php else: ?><span class="button disabled" aria-disabled="true">购买暂未开放</span><?php endif ?>
<a class="button secondary" href="/?view=downloads">下载</a>
</nav>
<?php else: ?>
<div class="download-list">
<?php $available=0;foreach ($state['apps'] as $game): $installer=$state['home_installers'][(string)$game['game_id']]??null;if (!$installer) continue;$available++; ?>
<article class="download-item"><div><h2><?=MTX\Http::escape($game['name'])?></h2><p>安装器 · <?=number_format($installer['bytes']/1048576,2)?> MiB</p></div><a class="button primary" href="/installer?game_id=<?=(int)$game['game_id']?>">下载</a></article>
<?php endforeach ?>
<?php if ($home['download_url']!==''): $available++; ?><article class="download-item"><div><h2>通用安装器</h2><p>前往安装器下载地址</p></div><a class="button primary" href="<?=MTX\Http::escape($home['download_url'])?>" rel="noopener noreferrer">下载</a></article><?php endif ?>
<?php if (!$available): ?><p class="empty">安装器准备中，下载即将开放。</p><?php endif ?>
</div><a class="back" href="/">← 返回主页</a>
<?php endif ?>
</section></main></body></html>
