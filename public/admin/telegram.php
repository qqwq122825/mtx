<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Security,TelegramBot,TelegramReplies,TelegramApiError,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','POST');Security::session($app);
if (!Security::loggedIn($app)) Http::redirect($app->path('/admin/login.php'));
header('Cache-Control: no-store');
$bot=new TelegramBot($app);
if ($_SERVER['REQUEST_METHOD']==='POST') {
    Security::csrf();
    try {
        switch (Http::text($_POST,'action',20)) {
            case 'save': $bot->saveSettings($_POST);$notice='配置已保存，尚未启用。部署到 HTTPS 后点击启用。';break;
            case 'replies': $bot->saveReplies($_POST);$notice='自动回复已保存，新消息立即使用，无需暂停机器人。';break;
            case 'connect': $bot->connect();$notice='Webhook 已启用，管理员已收到测试消息。';break;
            case 'disconnect': $bot->disconnect();$notice='机器人已暂停，Webhook 已移除。';break;
            case 'status': $status=$bot->status();$notice='Webhook '.($status['matches']?'地址匹配':'尚未指向本站').'；待处理 '.$status['pending'].' 条。'.($status['has_error']?' Telegram 记录过投递异常，请核对 HTTPS/CDN 配置。':'');break;
            default: throw new Problem(422,'操作类型异常。');
        }
        $_SESSION['telegram_notice']=['ok'=>true,'text'=>$notice];
    } catch (Problem|TelegramApiError $e) { $_SESSION['telegram_notice']=['ok'=>false,'text'=>$e->getMessage()]; }
    Http::redirect($app->path('/admin/telegram.php'));
}
$s=$bot->store->read();$c=$s['settings'];$replies=TelegramReplies::read($s);$configured=$c['token']!=='';$notice=$_SESSION['telegram_notice']??null;unset($_SESSION['telegram_notice']);
$stateLabel=$c['enabled']?'已启用':($configured?'已配置 · 暂停':'待配置');
$events=array_reverse($s['events']);$failures=count(array_filter($events,fn($e)=>in_array($e['status'],['failed','uncertain'],true)));
function e(mixed $v): string { return Http::escape($v); }
function hidden(string $action): void { echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.e($action).'">'; }
$labels=['delivered'=>'已处理','failed'=>'失败','uncertain'=>'结果待确认','filtered'=>'已过滤'];
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Telegram 客服 · 满天星</title><link rel="stylesheet" href="<?=e($app->path('/assets/app.css'))?>"><script src="<?=e($app->path('/assets/app.js'))?>" defer></script></head><body>
<header class="topbar bot-topbar"><a class="brand" href="<?=e($app->path('/admin/'))?>">✳ 满天星<span class="brand-sub">UPDATE CENTER</span></a><a class="button quiet" href="<?=e($app->path('/admin/'))?>">返回包管理</a></header>
<main class="content bot-page"><div class="page-heading"><div><div class="eyebrow">TELEGRAM SUPPORT</div><h1>让每条消息，都有回应。</h1><p class="muted">用户联系机器人，你在 Telegram 回复。无需公开私人账号，也无需数据库。</p></div><span class="subtle-tag"><?=e($stateLabel)?></span></div>
<nav class="bot-tabs" aria-label="机器人管理"><a aria-current="page" href="<?=e($app->path('/admin/telegram.php'))?>">连接与客服</a><a href="<?=e($app->path('/admin/telegram-announcements.php'))?>">公告卡片</a></nav>
<?php if ($notice): ?><div class="notice <?=$notice['ok']?'success':''?>" role="status"><?=e($notice['text'])?></div><?php endif ?>
<div class="metrics"><section class="metric"><span>机器人状态</span><strong><?=e($stateLabel)?></strong><small><?=$c['username']?'@'.e($c['username']):'等待 BotFather Token'?></small></section><section class="metric"><span>回复关联</span><strong><?=count($s['routes'])?></strong><small>仅保存消息 ID · 最长 30 天 / 10,000 条</small></section><section class="metric"><span>近期异常</span><strong><?=$failures?></strong><small>最近 100 条处理记录 · 不保存聊天正文</small></section></div>
<div class="work-grid"><section class="panel"><div class="section-title"><div><span class="step-number">01</span><h2>连接你的机器人</h2></div></div><p class="muted compact">首次填写 Token 和你的个人数字 ID。你需要先打开机器人，点击 Start。数字 ID 未知时，可在终端运行 <code>php bin/telegram-id.php</code> 按提示获取。</p>
<form method="post" action="<?=e($app->path('/admin/telegram.php'))?>" class="bot-form"><?php hidden('save') ?>
<label for="bot-token">Bot Token</label><input id="bot-token" name="token" type="password" autocomplete="new-password" maxlength="150" placeholder="<?=$configured?'已保存；留空保持不变':'粘贴 BotFather 提供的 Token'?>" <?=$configured?'':'required'?> <?=$c['enabled']?'disabled':''?>>
<p class="muted compact">Token 仅存于服务器私有目录，页面不回显。更换机器人或管理员会清空原会话、屏蔽列表和公告配置。存在待删除公告时，请先完成清理。</p>
<label for="bot-admin">管理员 Telegram 数字 ID</label><input id="bot-admin" name="admin_id" inputmode="numeric" pattern="[1-9][0-9]{0,15}" required value="<?=$c['admin_id']?e($c['admin_id']):''?>" placeholder="个人账号的数字 ID，不是 @用户名" <?=$c['enabled']?'disabled':''?>>
<button class="button primary full" type="submit" <?=$c['enabled']?'disabled':''?>>保存配置</button></form>
<div class="bot-actions"><form method="post" action="<?=e($app->path('/admin/telegram.php'))?>"><?php hidden('connect') ?><button class="button primary" type="submit" <?=!$configured || $c['enabled']?'disabled':''?>>启用 Webhook</button></form><form method="post" action="<?=e($app->path('/admin/telegram.php'))?>"><?php hidden('status') ?><button class="button quiet" type="submit" <?=$configured?'':'disabled'?>>检查连接</button></form><form method="post" action="<?=e($app->path('/admin/telegram.php'))?>" data-confirm="暂停后停止转发、回复和新公告投递；已发公告仍按计划删除。确认暂停？"><?php hidden('disconnect') ?><button class="text-button danger" type="submit" <?=$configured?'':'disabled'?>>暂停机器人</button></form></div>
<?php if ($c['username']): ?><p class="muted compact">用户入口：<a class="text-button" href="https://t.me/<?=e($c['username'])?>" target="_blank" rel="noopener noreferrer">@<?=e($c['username'])?></a></p><?php endif ?>
</section><section class="panel"><div class="section-title"><div><span class="step-number">02</span><h2>使用方式</h2></div></div><ol class="bot-steps"><li><strong>用户发给机器人</strong><p>支持文字、图片、文件、语音、视频和贴纸。只处理私聊，不读取群组或频道。</p></li><li><strong>你直接回复对应消息</strong><p>机器人将用户消息与会话卡片发给你。长按其中一条选择“回复”，回信会经机器人送达用户，不附带你的账号转发来源。</p></li><li><strong>管理骚扰消息</strong><p>回复用户消息发送 <code>/block</code> 屏蔽；发送 <code>/unblock 数字ID</code> 解除。<code>/who</code> 查询当前回复对象。</p></li></ol>
<div class="note-box"><strong>部署后再启用</strong><p>Webhook 需要公网 HTTPS；CDN 对此路径关闭缓存和浏览器挑战，保留 POST 与校验头。Webhook 地址不包含 Token。</p></div><div class="bot-endpoint"><span class="endpoint-label">Webhook 地址</span><code><?=e($bot->webhookURL())?></code></div>
<p class="muted compact">若 Telegram 限制账号与机器人交流，仍以平台实际提示为准。客服消息仅在用户主动联系后回复。群组 / 频道公告请在“公告卡片”页单独配置。</p></section></div>
<section class="panel bot-records" id="auto-replies"><div class="section-title"><div><span class="step-number">03</span><h2>默认自动回复</h2></div><span class="muted">即时生效 · 无需暂停</span></div>
<p class="muted compact">点击“开始”或发送 /start 展示欢迎语和三个快捷按钮。选择按钮后提示补充信息，实际问题仍转发给你。管理员也可以发送 /start 预览，发送 /help 查看管理指令。</p>
<form method="post" action="<?=e($app->path('/admin/telegram.php'))?>" class="bot-form"><?php hidden('replies') ?>
<?php foreach (TelegramReplies::LABELS as $key=>$label): ?><div><label for="reply-<?=e($key)?>"><?=e($label)?></label><textarea id="reply-<?=e($key)?>" name="<?=e($key)?>" rows="3" maxlength="1000" required><?=e($replies[$key])?></textarea></div><?php endforeach ?>
<p class="muted compact">纯文字，支持换行，每项最多 1000 字。消息收到提示仅在实际问题成功转发后发送，同一用户 30 分钟内最多一次；连续补充文字、图片时不重复打扰。保存文案不会发送消息或修改 Token、会话、公告设置。</p>
<button class="button primary full" type="submit">保存自动回复</button></form></section>
<section class="panel bot-records"><div class="section-title"><div><span class="step-number">04</span><h2>最近处理记录</h2></div><span class="muted">仅留状态与关联 ID</span></div>
<?php if (!$events): ?><div class="empty-state"><span class="empty-icon">✉</span><h3>等待第一条客服消息</h3><p>配置并启用后，处理状态会出现在这里。</p></div><?php else: ?><div class="bot-table-wrap"><table class="bot-table"><thead><tr><th>时间</th><th>会话 ID</th><th>Update ID</th><th>结果</th></tr></thead><tbody><?php foreach ($events as $event): ?><tr><td><?=e(gmdate('m-d H:i:s',$event['at']))?> UTC</td><td>#<?=e($event['peer'])?></td><td><?=e($event['update_id'])?></td><td><?=e($labels[$event['status']]??$event['status'])?><?=$event['code']?' · '.e($event['code']):''?></td></tr><?php endforeach ?></tbody></table></div><?php endif ?>
<p class="muted compact">网络超时等结果不明确时不自动重复发送，标记为“结果待确认”；管理员确认后再手动回复。请勿把 Token、验证码或其他凭据发给客服。</p></section>
<footer class="page-footer">MTX 客服机器人<span>单管理员 · 私聊双向转发 · 目录存储</span></footer></main></body></html>
