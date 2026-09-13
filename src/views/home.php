<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
MTX\Http::method('GET','HEAD');
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='HEAD') exit;
$home=MTX\HomeSettings::read($app->store);$state=$app->store->read();
if (!empty($state['home_installers'])) $home['download_url']='#installers';
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>满天星 · MTX</title><link rel="stylesheet" href="/home.css"></head><body>
<header><a class="brand" href="/">✳ 满天星 <small>MTX</small></a><span>官方入口</span></header>
<main><div class="eyebrow">M A N T I A N X I N G</div><h1>满天星</h1><p class="intro">从这里，开始。</p>
<div class="cards">
<?php foreach ([['buy_url','01','买卡','前往官方卡网，选择所需卡密。','前往买卡','买卡暂未开放'],['download_url','02','下载安装器','下载满天星安装器，开始安装。','下载安装器','下载即将开放']] as [$key,$num,$title,$desc,$label,$empty]): ?>
<section><span class="number"><?=$num?></span><h2><?=$title?></h2><p><?=$desc?></p><?php if ($home[$key]!==''): ?><a class="button <?=$key==='buy_url'?'primary':''?>" href="<?=MTX\Http::escape($home[$key])?>" rel="noopener noreferrer"><?=$label?> <span aria-hidden="true">↗</span></a><?php else: ?><span class="button disabled" aria-disabled="true"><?=$empty?></span><?php endif ?></section>
<?php endforeach ?>
</div>
<?php if (!empty($state['home_installers'])): ?><div class="downloads" id="installers"><h2>选择游戏 · 下载安装器</h2><div class="cards"><?php foreach ($state['apps'] as $game): $installer=$state['home_installers'][(string)$game['game_id']]??null;if (!$installer) continue; ?><section><h2><?=MTX\Http::escape($game['name'])?></h2><p>安装器 · <?=number_format($installer['bytes']/1048576,2)?> MiB</p><a class="button" href="/installer?game_id=<?=(int)$game['game_id']?>">下载安装器 <span aria-hidden="true">↓</span></a></section><?php endforeach ?></div></div><?php endif ?>
<p class="note">下载入口提供安装器，不是游戏辅助更新包。请通过官方入口获取。</p></main><footer>MTX · 满天星</footer></body></html>
