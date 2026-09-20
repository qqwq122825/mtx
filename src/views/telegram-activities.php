<?php
/** Included only after the existing admin password/session/CSRF checks. */
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER') || !isset($activityView) || !$activityView) { http_response_code(404);exit; }
use MTX\{Http,TelegramTrials};
$t=TelegramTrials::read($s);$activity=$t['activity'];$stats=TelegramTrials::summary($s);
$action=$app->path('/admin/telegram.php').'?view=activities';
function trialHidden(string $action): void { global $t; hidden($action);echo '<input type="hidden" name="revision" value="'.e($t['revision']).'">'; }
$records=array_reverse(array_filter($t['cards'],fn($card)=>$card['status']!=='available'),true);$records=array_slice($records,0,100,true);
$trialLabels=['available'=>'未分配','reserved'=>'已预留 · 待确认','delivered'=>'已发送','uncertain'=>'发送结果待确认','failed'=>'发送失败 · 保留原卡'];
$stockStatus=Http::text($_GET,'stock_status',20,'available');
if (!in_array($stockStatus,array_merge(['all'],array_keys($trialLabels)),true)) $stockStatus='available';
$stock=array_filter($t['cards'],fn($card)=>$stockStatus==='all' || $card['status']===$stockStatus);
$stockTotal=count($stock);$stockPages=max(1,(int)ceil($stockTotal/50));
$stockPage=min($stockPages,max(1,Http::integer(['page'=>$_GET['page']??'1'],'page')));
$stock=array_slice($stock,($stockPage-1)*50,50,true);
$stockUrl=fn(int $page)=>$action.'&stock_status='.rawurlencode($stockStatus).'&page='.$page.'#inventory';
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>活动设置 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>?v=admin2"><script src="<?=e($app->path('/assets/app.js'))?>?v=admin2" defer></script><?php MTX\AdminLayout::assets($app); ?><script src="<?=e($app->path('/assets/telegram-activities.js'))?>?v=1" defer></script></head><body>
<?php MTX\AdminLayout::begin($app,'活动与卡密','activities'); ?>
<main class="content bot-page"><div class="page-heading"><div><div class="eyebrow">TELEGRAM / ACTIVITIES</div><h1>活动设置</h1><p class="muted">两小时测试卡预设，一键开启，随时结束。</p></div><span class="subtle-tag"><?=$activity?($activity['enabled']?'领取已开启':'领取已暂停'):'尚未创建活动'?></span></div>

<?php if ($notice): ?><div class="notice <?=$notice['ok']?'success':'error'?>" role="status"><?=e($notice['text'])?></div><?php endif ?>
<div class="metrics"><section class="metric"><span>可领取库存</span><strong><?=$stats['available']?></strong><small>卡密明细见下方库存列表</small></section><section class="metric"><span>已分配</span><strong><?=$stats['allocated']?></strong><small>包括已发送、待确认和发送失败</small></section><section class="metric"><span>需要核对</span><strong><?=$stats['attention']?></strong><small>保留原卡，不自动退回库存</small></section></div>
<div class="work-grid"><section class="panel"><div class="section-title"><div><span class="step-number">01</span><h2>活动预设</h2></div></div>
<form method="post" action="<?=e($action)?>" class="bot-form"><?php trialHidden('trial_save') ?><label for="trial-title">领取按钮名称</label><input id="trial-title" name="title" required maxlength="36" value="<?=e($activity['title']??TelegramTrials::TITLE)?>">
<div class="note-box"><strong>每人每天 1 张 · 每张 2 小时</strong><p>以 Telegram 数字 ID 限领，北京时间 00:00 重置。同一天再次点击只展示原卡，不扣新库存。多个 Telegram 账号视为不同用户。</p><p>请导入卡密系统中已设置为两小时的卡。机器人仅管理领取，实际激活时间与到期由卡密系统控制。</p></div>
<label class="announcement-switch"><input type="checkbox" name="enabled" value="1" <?=($activity['enabled']??false)?'checked':''?>>开启领取入口</label><p class="muted compact">先保存预设 → 导入卡密 → 开启入口。开启后发送 /start 可见消息下方的领取按钮。关闭后，新欢迎消息隐藏按钮，旧按钮也停止领取。保存不会群发消息。</p>
<button class="button primary full" type="submit">保存活动设置</button></form>
<?php if ($activity): ?><form method="post" action="<?=e($action)?>" class="bot-actions"><?php trialHidden('trial_pause') ?><button class="button quiet" type="submit" <?=$activity['enabled']?'':'disabled'?>>暂停领取</button></form><?php endif ?>
</section><section class="panel"><div class="section-title"><div><span class="step-number">02</span><h2>卡密库存</h2></div></div>
<form method="post" action="<?=e($action)?>" class="bot-form" autocomplete="off"><?php trialHidden('trial_import') ?><label for="trial-codes">导入两小时测试卡（一行一张）</label><textarea id="trial-codes" name="codes" rows="10" maxlength="150000" spellcheck="false" autocomplete="off" placeholder="粘贴卡密，每行一张" required <?=$activity?'':'disabled'?>></textarea>
<p class="muted compact">每批最多 1000 张。本活动最多保存 10,000 张库存记录。自动跳过重复或曾分配的卡；导入后可在下方库存明细查看卡密；仅登录管理员可见，请勿公开分享。</p><button class="button primary full" type="submit" <?=$activity?'':'disabled'?>>导入库存</button></form>
<div class="note-box"><strong>没有库存时不扣次数</strong><p>发送失败或网络结果待确认时，该卡继续归原用户，避免重复发给别人。活动开启期间，用户当天再次点击可取回同一张卡。</p></div></section></div>
<section class="panel bot-records" id="inventory"><div class="section-title"><h2>库存卡密明细</h2><span class="muted">共 <?=$stockTotal?> 张 · 每页 50 张</span></div>
<form method="get" action="<?=e($app->path('/admin/telegram.php'))?>" class="bot-actions"><input type="hidden" name="view" value="activities"><label for="stock-status">库存状态</label><select id="stock-status" name="stock_status"><?php foreach (['all'=>'全部状态']+$trialLabels as $value=>$label): ?><option value="<?=e($value)?>" <?=$stockStatus===$value?'selected':''?>><?=e($label)?></option><?php endforeach ?></select><button class="button quiet" type="submit">筛选</button></form>
<p class="muted compact">完整卡密直接显示，可选中复制。可逐张多选或全选本页未分配库存，再批量删除；翻页或筛选后重新选择。已分配卡保留用于用户再次取卡。删除库存不影响活动设置，也不撤回 Telegram 消息。</p>
<form id="stock-delete" method="post" action="<?=e($stockUrl($stockPage))?>" data-confirm="确认删除所选未分配卡密？删除后这些卡将从机器人库存移除。活动和领取记录保留。"><?php trialHidden('trial_cards_delete') ?></form>
<?php if (!$stock): ?><div class="empty-state"><h3>暂无符合条件的卡密</h3><p>请导入卡密或切换库存状态查看。</p></div><?php else: ?>
<div class="bot-actions"><span id="stock-selection-count" class="muted" role="status">已选 0 张</span><button id="stock-clear-selection" type="button" class="button quiet">取消选择</button><button class="text-button danger" type="submit" form="stock-delete" data-stock-bulk>删除勾选卡密</button></div>
<div class="bot-table-wrap"><table class="bot-table"><thead><tr><th><label><input id="stock-select-all" type="checkbox"> 全选本页</label></th><th>卡密</th><th>状态</th><th>领取用户</th><th>操作</th></tr></thead><tbody>
<?php foreach ($stock as $hash=>$card): ?><tr><td><?php if ($card['status']==='available'): ?><input type="checkbox" name="cards[]" value="<?=e($hash)?>" form="stock-delete" aria-label="选择卡密 <?=e(substr($hash,0,12))?>"><?php else: ?>—<?php endif ?></td><td><input type="text" readonly autocomplete="off" aria-label="完整卡密" value="<?=e($card['code'])?>" style="min-width:220px;width:100%;font-family:monospace"></td><td><?=e($trialLabels[$card['status']]??$card['status'])?></td><td><?=$card['peer']?'#'.e($card['peer']):'—'?></td><td><?php if ($card['status']==='available'): ?><button class="text-button danger" type="submit" name="card" value="<?=e($hash)?>" form="stock-delete">删除此卡</button><?php else: ?><span class="muted">已分配 · 保留</span><?php endif ?></td></tr><?php endforeach ?>
</tbody></table></div><div class="bot-actions"><button class="text-button danger" type="submit" form="stock-delete" data-stock-bulk>删除勾选卡密</button></div>
<?php endif ?>
<nav class="bot-actions" aria-label="库存分页"><?php if ($stockPage>1): ?><a class="button quiet" href="<?=e($stockUrl($stockPage-1))?>">上一页</a><?php endif ?><span class="muted">第 <?=$stockPage?> / <?=$stockPages?> 页</span><?php if ($stockPage<$stockPages): ?><a class="button quiet" href="<?=e($stockUrl($stockPage+1))?>">下一页</a><?php endif ?></nav></section>
<section class="panel bot-records"><div class="section-title"><h2>领取记录</h2><span class="muted">本活动最近 100 条 · 原始卡密可选中复制</span></div>
<?php if (!$records): ?><div class="empty-state"><h3>还没有领取记录</h3><p>用户点击领取后，这里显示数字 ID、领取日期、原始卡密和发送状态。</p></div><?php else: ?><div class="bot-table-wrap"><table class="bot-table"><thead><tr><th>领取时间（北京时间）</th><th>用户 ID</th><th>原始卡密</th><th>发送状态</th></tr></thead><tbody><?php foreach ($records as $hash=>$card): ?><tr><td><?=e((new DateTimeImmutable('@'.$card['at']))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('m-d H:i:s'))?></td><td>#<?=e($card['peer'])?></td><td><input type="text" readonly autocomplete="off" aria-label="领取记录原始卡密" value="<?=e($card['code'])?>" style="min-width:220px;width:100%;font-family:monospace"></td><td><?=e($trialLabels[$card['status']]??$card['status'])?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p class="muted compact">“已发送”代表 Telegram 接收成功，不代表用户已读或卡片已激活。保留数字 ID 是为了限领和核对，不记录用户名或聊天内容。</p></section>
<?php if ($activity): ?><section class="panel bot-records"><div class="section-title"><h2>结束并删除活动</h2></div><p class="muted compact">只是暂时不发卡，请使用“暂停领取”。删除会清除本活动的配置和全部卡密原文，已发 Telegram 消息不撤回。当天限领记录及已分配卡的 SHA-256 指纹继续保留，重新创建也不会给同一用户当天多发、或重复导入已分配卡。新活动使用新的按钮标识，旧按钮永久失效。</p>
<form method="post" action="<?=e($action)?>" class="bot-form" data-confirm="确认删除活动和全部库存原文？此操作不撤回已发卡片，暂停领取可保留库存。"><?php trialHidden('trial_delete') ?><label for="trial-delete">输入“删除活动”确认</label><input id="trial-delete" name="confirm_delete" required autocomplete="off" placeholder="删除活动"><button class="text-button danger" type="submit">删除活动及库存</button></form></section><?php endif ?>
<footer class="page-footer">MTX 活动管理<span>单活动预设 · 私有目录存储 · 无数据库</span></footer></main><?php MTX\AdminLayout::end(); ?></body></html>
