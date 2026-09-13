<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
MTX\Http::method('GET','HEAD');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
$home=MTX\HomeSettings::read($app->store);$state=$app->store->read();
$downloads=($_GET['view']??'')==='downloads';
$items=[];
foreach ($state['apps'] as $game) {
    $installer=$state['home_installers'][(string)$game['game_id']]??null;
    if ($installer) $items[]=['name'=>$game['name'],'url'=>'/installer?game_id='.(int)$game['game_id'],'meta'=>strtoupper($installer['extension']).' · '.number_format($installer['bytes']/1048576,2).' MiB'];
}
if ($home['download_url']!=='') $items[]=['name'=>'通用安装器','url'=>$home['download_url'],'meta'=>'安装器下载'];
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#f7f9ff"><title><?=$downloads?'下载中心 · 满天星':'满天星 · MTX'?></title><link rel="stylesheet" href="/home.css?v=3"></head>
<body class="<?=$downloads?'downloads-page':'home-page'?>">
<header class="site-header"><a class="brand" href="/" aria-label="满天星主页"><span class="brand-symbol" aria-hidden="true">✳</span><span>满天星</span></a><?php if ($downloads): ?><a class="back-link" href="/">← 返回主页</a><?php else: ?><span class="header-wordmark" aria-hidden="true">MTX</span><?php endif ?></header>
<?php if (!$downloads): ?>
<main class="home-main"><section class="entrance" aria-labelledby="home-title">
<div class="emblem" aria-hidden="true"><span>m<span class="emblem-t">t</span>x<span class="spark">✦</span></span></div>
<h1 id="home-title">满天星</h1><div class="title-rule" aria-hidden="true"></div>
<nav class="home-actions" aria-label="主要入口">
<?php if ($home['buy_url']!==''): ?><a class="action primary" href="<?=MTX\Http::escape($home['buy_url'])?>" rel="noopener noreferrer"><span>购买</span><span class="arrow" aria-hidden="true">↗</span></a><?php else: ?><span class="action disabled" aria-disabled="true">购买暂未开放</span><?php endif ?>
<a class="action secondary" href="/?view=downloads"><span>下载</span><span class="arrow" aria-hidden="true">↓</span></a>
</nav></section></main>
<?php else: ?>
<main class="downloads-main"><div class="page-heading"><span class="eyebrow">DOWNLOADS</span><h1>下载中心<span class="heading-dot" aria-hidden="true">.</span></h1><p>选择游戏，获取对应安装器。</p></div>
<section class="library" aria-label="安装器列表"><div class="library-heading"><h2>游戏安装器</h2><span><?=count($items)?> 款</span></div>
<?php if ($items): ?><div class="download-list"><?php foreach ($items as $item): ?>
<article class="download-item"><span class="app-icon" aria-hidden="true">✳</span><div class="app-info"><h3><?=MTX\Http::escape($item['name'])?></h3><p><?=MTX\Http::escape($item['meta'])?></p></div><a class="download-button" href="<?=MTX\Http::escape($item['url'])?>" aria-label="下载<?=MTX\Http::escape($item['name'])?>" rel="noopener noreferrer">下载 <span aria-hidden="true">↓</span></a></article>
<?php endforeach ?></div>
<?php else: ?><div class="empty"><div class="empty-icon" aria-hidden="true">↓</div><h3>安装器准备中</h3><p>开放下载后，会显示在这里。</p><a class="empty-back" href="/">返回主页 <span aria-hidden="true">↗</span></a></div><?php endif ?>
</section></main>
<?php endif ?>
<footer class="site-footer"><span>MTX</span><span>满天星</span></footer>
</body></html>
