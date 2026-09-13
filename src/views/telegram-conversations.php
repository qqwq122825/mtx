<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER') || !($conversationView??false)) { http_response_code(404);exit; }
$history=$bot->conversations->read($c);$endpoint=$app->path('/admin/telegram.php').'?view=conversations';
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>客服会话 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>?v=admin2"><link rel="stylesheet" href="<?=e($app->path('/admin/telegram.php'))?>?asset=conversations-css"><script src="<?=e($app->path('/admin/telegram.php'))?>?asset=conversations-js" defer></script><?php MTX\AdminLayout::assets($app); ?><script src="<?=MTX\Http::escape($app->path('/assets/app.js'))?>?v=admin2" defer></script></head><body>
<?php MTX\AdminLayout::begin($app,'客服会话','conversations'); ?>
<main class="content bot-page conversation-page"><div class="page-heading"><div><div class="eyebrow">TELEGRAM / INBOX</div><h1>客服会话</h1><p class="muted">谁发了消息、说了什么、你回复了什么，一处查看。</p></div><span class="subtle-tag"><?=$history['enabled']?'后续消息正在记录':'新消息记录已关闭'?></span></div>

<?php if ($notice): ?><div class="notice <?=$notice['ok']?'success':'error'?>" role="status"><?=e($notice['text'])?></div><?php endif ?>
<div id="conversation-app" data-endpoint="<?=e($endpoint)?>" data-csrf="<?=e($_SESSION['csrf'])?>"><p class="muted">正在载入会话列表…</p></div><noscript><div class="notice">请启用 JavaScript 查看会话表格。</div></noscript>
<details class="panel conversation-settings"><summary>记录与隐私设置</summary><p class="muted compact">仅记录此功能上线后的有效用户咨询和管理员人工回复。自动欢迎语、测试卡发放不进入此列表；历史聊天不补抓。图片、语音和文件仅显示类型、文件名及附带文字，不下载附件。完整附件继续在 Telegram 查看。</p>
<form method="post" action="<?=e($endpoint)?>" class="bot-form"><?php hidden('conversation_config') ?><input type="hidden" name="revision" value="<?=e($history['revision'])?>"><label class="announcement-switch"><input type="checkbox" name="enabled" value="1" <?=$history['enabled']?'checked':''?>>保存后续咨询和人工回复</label><label for="history-days">保留期限</label><select id="history-days" name="days"><?php foreach ([7,30,90] as $days): ?><option value="<?=$days?>" <?=$history['days']===$days?'selected':''?>><?=$days?> 天</option><?php endforeach ?></select><p class="muted compact">默认 30 天，最多保留最近 5000 条消息。缩短期限会清理超期正文；关闭记录不影响 Telegram 正常收发。服务器现有每分钟定时任务负责过期清理。记录在私有目录，仅登录管理员可见，更换机器人绑定后不展示旧绑定记录。</p><button class="button primary" type="submit">保存记录设置</button></form></details>
<footer class="page-footer">MTX 客服会话<span>Element Plus · 私有目录存储 · 无数据库</span></footer></main><?php MTX\AdminLayout::end(); ?></body></html>
