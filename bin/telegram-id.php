<?php
/** Read-only helper for a NEW bot. No webhook deletion, no update acknowledgement. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
try {
    echo "输入新机器人的 Bot Token（不写入文件）：";
    $tty=function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($tty) system('stty -echo');
    try { $token=trim(fgets(STDIN)?:''); } finally { if ($tty) system('stty echo');echo "\n"; }
    if (!preg_match('/\A[1-9][0-9]{4,19}:[A-Za-z0-9_-]{30,100}\z/',$token)) throw new RuntimeException('Token 格式异常。');
    $api=new MTX\TelegramApi();$status=$api->call($token,'getWebhookInfo');
    if (!is_array($status) || ($status['url']??'')!=='') throw new RuntimeException('此机器人已有 Webhook。本工具仅用于尚未接入的新机器人，不改变现有接入。');
    $me=$api->call($token,'getMe');
    if (!is_array($me) || !preg_match('/\A[A-Za-z0-9_]{5,64}\z/',$me['username']??'')) throw new RuntimeException('机器人信息异常。');
    $command='/id '.bin2hex(random_bytes(8));
    echo '用你自己的 Telegram 私聊 @'.$me['username'].'，发送：'.$command."\n发送后回到这里按回车读取 ID。\n";fgets(STDIN);
    $updates=$api->call($token,'getUpdates',['limit'=>100,'timeout'=>0,'allowed_updates'=>['message']]);
    $id=MTX\TelegramBot::findPairingID(is_array($updates)?$updates:[],$command);
    if (!$id) throw new RuntimeException('暂未读到匹配消息，请确认已发送后重新运行。');
    echo '你的 Telegram 数字 ID：'.$id."\n把该 ID 和 Token 填入密码后台的 Telegram 客服页面。\n";
} catch (Throwable $e) { fwrite(STDERR,($e instanceof MTX\TelegramApiError?$e->getMessage():($e instanceof RuntimeException?$e->getMessage():'读取未完成，请检查 PHP 配置。'))."\n");exit(1); }
