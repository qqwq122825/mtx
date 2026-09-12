<?php
/** Included only after the existing admin password/session/CSRF checks. */
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER') || !isset($activityView) || !$activityView) { http_response_code(404);exit; }
use MTX\TelegramTrials;
$t=TelegramTrials::read($s);$activity=$t['activity'];$stats=TelegramTrials::summary($s);
$action=$app->path('/admin/telegram.php').'?view=activities';
function trialHidden(string $action): void { global $t; hidden($action);echo '<input type="hidden" name="revision" value="'.e($t['revision']).'">'; }
$records=array_reverse(array_filter($t['cards'],fn($card)=>$card['status']!=='available'),true);$records=array_slice($records,0,100,true);
$trialLabels=['reserved'=>'已预留 · 待确认','delivered'=>'已发送','uncertain'=>'发送结果待确认','failed'=>'发送失败 · 保留原卡'];
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>活动设置 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>"><script src="<?=e($app->path('/assets/app.js'))?>" defer></script></head><body>
<header class="topbar bot-topbar"><a class="brand" href="<?=e($app->path('/admin/'))?>">✳ 满天星<span class="brand-sub">UPDATE CENTER</span></a><a class="button quiet" href="<?=e($app->path('/admin/telegram.php'))?>">返回客服</a></header>
<main class="content bot-page"><div class="page-heading"><div><div class="eyebrow">TELEGRAM / ACTIVITIES</div><h1>活动设置</h1><p class="muted">两小时测试卡预设，一键开启，随时结束。</p></div><span class="subtle-tag"><?=$activity?($activity['enabled']?'领取已开启':'领取已暂停'):'尚未创建活动'?></span></div>
<nav class="bot-tabs" aria-label="机器人管理"><a href="<?=e($app->path('/admin/telegram.php'))?>">连接与客服</a><a href="<?=e($app->path('/admin/telegram-announcements.php'))?>">公告卡片</a><a aria-current="page" href="<?=e($action)?>">活动设置</a></nav>
<?php if ($notice): ?><div class="notice <?=$notice['ok']?'success':'error'?>" role="status"><?=e($notice['text'])?></div><?php endif ?>
<div class="metrics"><section class="metric"><span>可领取库存</span><strong><?=$stats['available']?></strong><small>完整卡密仅存服务器私有目录</small></section><section class="metric"><span>已分配</span><strong><?=$stats['allocated']?></strong><small>包括已发送、待确认和发送失败</small></section><section class="metric"><span>需要核对</span><strong><?=$stats['attention']?></strong><small>保留原卡，不自动退回库存</small></section></div>
<div class="work-grid"><section class="panel"><div class="section-title"><div><span class="step-number">01</span><h2>活动预设</h2></div></div>
<form method="post" action="<?=e($action)?>" class="bot-form"><?php trialHidden('trial_save') ?><label for="trial-title">领取按钮名称</label><input id="trial-title" name="title" required maxlength="36" value="<?=e($activity['title']??TelegramTrials::TITLE)?>">
<div class="note-box"><strong>每人每天 1 张 · 每张 2 小时</strong><p>以 Telegram 数字 ID 限领，北京时间 00:00 重置。同一天再次点击只展示原卡，不扣新库存。多个 Telegram 账号视为不同用户。</p><p>请导入卡密系统中已设置为两小时的卡。机器人仅管理领取，实际激活时间与到期由卡密系统控制。</p></div>
<label class="announcement-switch"><input type="checkbox" name="enabled" value="1" <?=($activity['enabled']??false)?'checked':''?>>开启领取入口</label><p class="muted compact">先保存预设 → 导入卡密 → 开启入口。开启后发送 /start 可见消息下方的领取按钮。关闭后，新欢迎消息隐藏按钮，旧按钮也停止领取。保存不会群发消息。</p>
<button class="button primary full" type="submit">保存活动设置</button></form>
<?php if ($activity): ?><form method="post" action="<?=e($action)?>" class="bot-actions"><?php trialHidden('trial_pause') ?><button class="button quiet" type="submit" <?=$activity['enabled']?'':'disabled'?>>暂停领取</button></form><?php endif ?>
</section><section class="panel"><div class="section-title"><div><span class="step-number">02</span><h2>卡密库存</h2></div></div>
<form method="post" action="<?=e($action)?>" class="bot-form" autocomplete="off"><?php trialHidden('trial_import') ?><label for="trial-codes">导入两小时测试卡（一行一张）</label><textarea id="trial-codes" name="codes" rows="10" maxlength="150000" spellcheck="false" autocomplete="off" placeholder="粘贴卡密，每行一张" required <?=$activity?'':'disabled'?>></textarea>
<p class="muted compact">每批最多 1000 张。本活动最多保存 10,000 张库存记录。自动跳过重复或曾分配的卡；导入完成后页面不回显完整卡密，也不会写入 Git。</p><button class="button primary full" type="submit" <?=$activity?'':'disabled'?>>导入库存</button></form>
<div class="note-box"><strong>没有库存时不扣次数</strong><p>发送失败或网络结果待确认时，该卡继续归原用户，避免重复发给别人。活动开启期间，用户当天再次点击可取回同一张卡。</p></div></section></div>
<section class="panel bot-records"><div class="section-title"><h2>领取记录</h2><span class="muted">本活动最近 100 条 · 不展示完整卡密</span></div>
<?php if (!$records): ?><div class="empty-state"><h3>还没有领取记录</h3><p>用户点击领取后，这里显示数字 ID、领取日期和发送状态。</p></div><?php else: ?><div class="bot-table-wrap"><table class="bot-table"><thead><tr><th>领取时间（北京时间）</th><th>用户 ID</th><th>卡片指纹</th><th>发送状态</th></tr></thead><tbody><?php foreach ($records as $hash=>$card): ?><tr><td><?=e((new DateTimeImmutable('@'.$card['at']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('m-d H:i:s'))?></td><td>#<?=e($card['peer'])?></td><td><?=e(substr($hash,0,12))?></td><td><?=e($trialLabels[$card['status']]??$card['status'])?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p class="muted compact">“已发送”代表 Telegram 接收成功，不代表用户已读或卡片已激活。保留数字 ID 是为了限领和核对，不记录用户名或聊天内容。</p></section>
<?php if ($activity): ?><section class="panel bot-records"><div class="section-title"><h2>结束并删除活动</h2></div><p class="muted compact">只是暂时不发卡，请使用“暂停领取”。删除会清除本活动的配置和全部卡密原文，已发 Telegram 消息不撤回。当天限领记录及已分配卡的 SHA-256 指纹继续保留，重新创建也不会给同一用户当天多发、或重复导入已分配卡。新活动使用新的按钮标识，旧按钮永久失效。</p>
<form method="post" action="<?=e($action)?>" class="bot-form" data-confirm="确认删除活动和全部库存原文？此操作不撤回已发卡片，暂停领取可保留库存。"><?php trialHidden('trial_delete') ?><label for="trial-delete">输入“删除活动”确认</label><input id="trial-delete" name="confirm_delete" required autocomplete="off" placeholder="删除活动"><button class="text-button danger" type="submit">删除活动及库存</button></form></section><?php endif ?>
<footer class="page-footer">MTX 活动管理<span>单活动预设 · 私有目录存储 · 无数据库</span></footer></main></body></html>
