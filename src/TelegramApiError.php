<?php
declare(strict_types=1);
namespace MTX;
final class TelegramApiError extends \RuntimeException
{
    public function __construct(public readonly int $apiCode=0, public readonly bool $uncertain=false, public readonly int $retryAfter=0)
    { parent::__construct($uncertain?'Telegram 请求结果待确认，请检查后台记录。':'Telegram 请求未完成（代码 '.$apiCode.'），请检查机器人配置或聊天状态。'); }
}
