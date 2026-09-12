<?php
/** Server cron entry point. No HTTP route; never prints credentials or announcement text. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $result=(new MTX\TelegramAnnouncements(new MTX\App()))->tick();
    echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
} catch (Throwable) { fwrite(STDERR,"Telegram scheduler failed. Check configuration and private storage permissions.\n");exit(1); }
