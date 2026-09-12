<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404);exit; }
use MTX\{Http,Security,TelegramAnnouncements,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','POST');Security::session($app);
if (!Security::loggedIn($app)) Http::redirect($app->path('/admin/login.php'));
header('Cache-Control: no-store');
$ann=new TelegramAnnouncements($app);$notice=null;$draft=null;
$labels=['sent'=>'发送成功','retry'=>'等待重试','failed'=>'失败，请检查权限 / 配置','uncertain'=>'结果待确认，请在 Telegram 核对','pending'=>'发送结果待确认','cancelled'=>'已取消'];
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::csrf();
    try {
        switch (Http::text($_POST,'action',20)) {
            case 'save': $ann->save($_POST);$message='公告已保存。保存不会立即发送；已发送公告的删除计划保持不变。';break;
            case 'send': case 'test':
                $status=$ann->sendNow(Http::text($_POST,'request_id',32),$_POST['action']==='test');
                $message=($_POST['action']==='test'?'管理员测试：':'群组 / 频道投递：').($labels[$status]??$status).'。';break;
            case 'pause': $ann->pause();$message='定时发送已暂停，未发出的重试已取消；已有公告仍按原计划删除。';break;
            default: throw new Problem(422,'操作类型异常。');
        }
        $_SESSION['announcement_notice']=['ok'=>!isset($status) || $status==='sent','text'=>$message];Http::redirect($app->path('/admin/telegram-announcements.php'));
    } catch (Problem $e) {
        $notice=['ok'=>false,'text'=>$e->getMessage()];
        if (($_POST['action']??'')==='save') $draft=$_POST;
    }
}
$a=$ann->read();$card=$a['card'];$configured=$ann->store->read()['settings']['enabled'];
if (!$notice && isset($_SESSION['announcement_notice'])) {$notice=$_SESSION['announcement_notice'];unset($_SESSION['announcement_notice']);}
$values=$card+['first_at'=>''];
foreach ($card['buttons'] as $i=>$b) {$values['button'.($i+1).'_text']=$b['text'];$values['button'.($i+1).'_url']=$b['url'];}
if ($draft) {
    foreach ($values as $key=>$v) if (is_string($draft[$key]??null)) $values[$key]=$draft[$key];
    foreach (['schedule_enabled','delete_enabled'] as $key) $values[$key]=($draft[$key]??'0')==='1';
}
function e(mixed $v): string { return Http::escape($v); }
function hidden(string $action): void { echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.e($action).'">'; }
function dateLabel(int $at): string { return $at?(new DateTimeImmutable('@'.$at))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('m-d H:i:s'):'—'; }
$jobs=array_reverse($a['jobs'],true);
$waiting=count(array_filter($jobs,fn($j)=>in_array($j['delete_status'],['waiting','pending','retry'],true)));
$deletionLabels=['none'=>'不删除','waiting'=>'等待删除','pending'=>'删除待确认','retry'=>'删除重试中','deleted'=>'已删除','failed'=>'删除失败，请手动核对','expired'=>'已超过删除时限'];
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>机器人公告 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>"><script src="<?=e($app->path('/assets/app.js'))?>" defer></script><script src="<?=e($app->path('/assets/telegram-announcements.js'))?>" defer></script></head><body>
<header class="topbar bot-topbar"><a class="brand" href="<?=e($app->path('/admin/'))?>">✳ 满天星<span class="brand-sub">UPDATE CENTER</span></a><a class="button quiet" href="<?=e($app->path('/admin/telegram.php'))?>">机器人连接</a></header>
<main class="content bot-page announcement-page">
<div class="page-heading"><div><div class="eyebrow">TELEGRAM / ANNOUNCEMENT</div><h1>机器人公告</h1><p class="muted">编辑卡片、定时投递、到时删除。</p></div><span class="subtle-tag"><?=$a['card']['schedule_enabled']?'定时发送已开启':'定时发送已关闭'?></span></div>
<nav class="bot-tabs" aria-label="机器人管理"><a href="<?=e($app->path('/admin/telegram.php'))?>">连接与客服</a><a aria-current="page" href="<?=e($app->path('/admin/telegram-announcements.php'))?>">公告卡片</a><a href="<?=e($app->path('/admin/telegram.php'))?>?view=activities">活动设置</a></nav>
<?php if ($notice): ?><div class="notice <?=$notice['ok']?'success':'error'?>" role="status"><?=e($notice['text'])?></div><?php endif ?>
<div class="announcement-grid"><section class="panel"><div class="section-title"><h2>编辑公告</h2><span class="muted">保存后再发送</span></div>
<form method="post" class="announcement-form" id="announcement-form" action="<?=e($app->path('/admin/telegram-announcements.php'))?>"><?php hidden('save') ?>
<label for="announcement-text">正文</label><textarea id="announcement-text" name="text" rows="14" required maxlength="4096"><?=e($values['text'])?></textarea><p class="muted compact">用 **文字** 加粗，*文字* 倾斜；换行直接保留。HTML 按普通文字显示。</p>
<div class="form-grid"><div><label for="contact">唯一客服</label><input id="contact" name="contact" maxlength="33" value="<?=e($values['contact'])?>" placeholder="@你的客服账号"></div><div><label for="bot-contact">双向联系机器人</label><input id="bot-contact" name="bot_contact" maxlength="33" value="<?=e($values['bot_contact'])?>" placeholder="@你的机器人"></div></div>
<p class="muted compact">联系方式留空则不展示。示例中的其他账号未填入，请使用你自己的入口。</p>
<fieldset class="announcement-fieldset"><legend>底部链接按钮</legend><?php for ($i=1;$i<=2;$i++): ?><div class="form-grid button-fields"><div><label for="button<?=$i?>-text">按钮 <?=$i?> 名称</label><input id="button<?=$i?>-text" name="button<?=$i?>_text" maxlength="40" value="<?=e($values['button'.$i.'_text'])?>"></div><div><label for="button<?=$i?>-url">HTTPS 地址</label><input id="button<?=$i?>-url" name="button<?=$i?>_url" type="url" maxlength="2048" placeholder="https://…" value="<?=e($values['button'.$i.'_url'])?>"></div></div><?php endfor ?><p class="muted compact">地址留空则隐藏该按钮。Telegram 会把按钮放在消息下方。</p></fieldset>
<fieldset class="announcement-fieldset"><legend>投递与定时</legend><label for="target">群组 / 频道</label><input id="target" name="target" maxlength="40" value="<?=e($values['target'])?>" placeholder="@频道用户名 或 -100 开头的数字 ID"><p class="muted compact">把机器人加入目标群组或频道，并授予发消息、删除消息的权限。此处不接受个人账号数字 ID。</p>
<label class="announcement-switch"><input type="checkbox" name="schedule_enabled" value="1" <?=$values['schedule_enabled']?'checked':''?>>定时发送公告</label>
<div class="form-grid"><div><label for="schedule-mode">发送方式</label><select id="schedule-mode" name="schedule_mode"><option value="interval" <?=$values['schedule_mode']==='interval'?'selected':''?>>按间隔重复</option><option value="once" <?=$values['schedule_mode']==='once'?'selected':''?>>只发送一次</option></select></div><div><label for="interval-minutes">间隔 / 默认首次延后（分钟）</label><input id="interval-minutes" type="number" name="interval_minutes" min="5" max="43200" required value="<?=e($values['interval_minutes'])?>"></div></div>
<label for="first-at">首次发送时间（北京时间，可选）</label><input id="first-at" type="datetime-local" name="first_at" value="<?=e($values['first_at'])?>"><p class="muted compact">留空则从本次保存起延后上述分钟数。再次保存会重新安排首次发送。</p>
<label class="announcement-switch"><input type="checkbox" name="delete_enabled" value="1" <?=$values['delete_enabled']?'checked':''?>>发送后自动删除</label><label for="delete-minutes">发送后保留（分钟）</label><input id="delete-minutes" type="number" name="delete_minutes" min="1" max="2820" required value="<?=e($values['delete_minutes'])?>"><p class="muted compact">1 分钟至 47 小时。仅删除本面板发送的公告，不删除用户消息或客服回信。已发送的公告保留原删除计划。</p></fieldset>
<button type="submit" class="button primary full">保存公告配置</button></form></section>
<aside class="announcement-aside"><section class="announcement-preview" aria-label="Telegram 卡片预览"><div class="preview-heading"><span>卡片预览</span><span id="preview-state">本地预览 · 尚未发送</span></div><div class="telegram-bubble" id="announcement-preview"><?=TelegramAnnouncements::html($card)?></div><div class="telegram-buttons" id="announcement-buttons"><?php foreach ($card['buttons'] as $b): if (!$b['url']) continue; ?><span><?=e($b['text'])?> ↗</span><?php endforeach ?></div><p class="preview-footnote">示意预览，实际外观随 Telegram 主题变化。</p></section>
<section class="panel announcement-publish"><h2>检查后发送</h2><p class="muted compact">先保存，再发给管理员检查；确认入口正确后投递到目标群组 / 频道。发送使用已保存的配置。</p>
<div class="bot-actions"><form method="post" action="<?=e($app->path('/admin/telegram-announcements.php'))?>" data-confirm="发送已保存的公告给机器人管理员进行测试？"><?php hidden('test') ?><input type="hidden" name="request_id" value="<?=e(bin2hex(random_bytes(16)))?>"><button data-saved-send class="button quiet" <?=$configured?'':'disabled'?>>发给我测试</button></form><form method="post" action="<?=e($app->path('/admin/telegram-announcements.php'))?>" data-confirm="立即将已保存的公告发送到 <?=e($card['target']?:'尚未配置的群组 / 频道')?>？"><?php hidden('send') ?><input type="hidden" name="request_id" value="<?=e(bin2hex(random_bytes(16)))?>"><button data-saved-send class="button primary" <?=$configured && $card['target']!==''?'':'disabled'?>>立即投递</button></form></div>
<?php if (!$configured): ?><p class="muted compact">先到“机器人连接”页启用你的机器人，编辑和预览可先完成。</p><?php endif ?>
<dl class="announcement-status"><dt>下次发送（北京时间）</dt><dd><?=e(dateLabel($a['next_at']))?></dd><dt>待删除公告</dt><dd><?=$waiting?> 条</dd><dt>定时脚本最近运行</dt><dd><?=e(dateLabel($a['last_tick']))?></dd></dl>
<?php if (!$a['last_tick'] || $a['last_tick']<time()-180): ?><div class="note-box"><strong>定时脚本尚未运行或已停止</strong><p>服务器需每分钟执行一次下方脚本。关闭网页不影响服务器任务；脚本停机期间的删除可能延迟。</p></div><?php endif ?>
<form method="post" action="<?=e($app->path('/admin/telegram-announcements.php'))?>" class="bot-actions" data-confirm="暂停定时发送并取消未发出的重试？已有公告仍按原计划删除。"><?php hidden('pause') ?><button class="text-button danger">暂停定时发送</button></form>
<details class="announcement-cron"><summary>服务器定时任务配置</summary><p>宝塔计划任务：Shell 脚本，每 1 分钟执行；用户与 PHP-FPM 相同，并可写入 storage。</p><code>cd /srv/mtx &amp;&amp; /usr/bin/php bin/telegram-tick.php</code><p>按实际路径调整 PHP 和项目目录。PHP CLI 需要 curl、mbstring 扩展。本地预览不会替你安装服务器定时任务。</p></details></section></aside></div>
<section class="panel bot-records"><div class="section-title"><h2>投递与删除记录</h2><span class="muted">最近 100 条已完成记录 + 待处理任务</span></div>
<?php if (!$jobs): ?><div class="empty-state"><h3>还没有投递记录</h3><p>保存公告不会发送消息。测试或投递后，这里会显示结果。</p></div><?php else: ?><div class="bot-table-wrap"><table class="bot-table"><thead><tr><th>时间（北京时间）</th><th>位置 / 消息 ID</th><th>投递</th><th>删除</th></tr></thead><tbody><?php foreach ($jobs as $j): ?><tr><td><?=e(dateLabel($j['at']))?><br><?=e(['test'=>'管理员测试','manual'=>'手动投递','schedule'=>'定时投递'][$j['source']])?></td><td><?=e($j['chat_id']?:'待解析')?> / <?=e($j['message_id']?:'—')?></td><td><?=e($labels[$j['status']]??$j['status'])?><?=$j['code']?' · '.e($j['code']):''?></td><td><?=e($deletionLabels[$j['delete_status']]??$j['delete_status'])?><?php if ($j['delete_at']): ?><br><?=e(dateLabel($j['delete_at']))?><?php endif ?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p class="muted compact">发送超时或进程中断时，先在 Telegram 核对实际结果，系统不重复发送结果待确认的消息。删除失败时请检查权限并在 Telegram 手动处理。关闭自动删除只影响后续新消息。</p></section>
<footer class="page-footer">MTX 机器人管理<span>文件存储 · 无数据库</span></footer></main></body></html>
