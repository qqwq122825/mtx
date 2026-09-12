<?php
declare(strict_types=1);
namespace MTX;
/** Fixed HTTPS destination; transport injection is only used by local PHP tests. */
final class TelegramApi
{
    public function __construct(private readonly ?\Closure $transport=null) {}
    public function call(#[\SensitiveParameter] string $token,string $method,#[\SensitiveParameter] array $params=[]): array|bool
    {
        if (!in_array($method,['getMe','getUpdates','setWebhook','deleteWebhook','getWebhookInfo','sendMessage','copyMessage'],true)) throw new \LogicException('Unsupported Telegram method');
        $json=json_encode((object)$params,JSON_THROW_ON_ERROR);
        if ($this->transport) return ($this->transport)($method,$params,$json);
        if (!function_exists('curl_init')) throw new TelegramApiError(0);
        $curl=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
        $raw='';
        curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$json,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>static function ($ch,string $part) use (&$raw): int { if (strlen($raw)+strlen($part)>1048576) return 0; $raw.=$part; return strlen($part); }]);
        $ok=curl_exec($curl);$status=(int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        // Never surface the URL, token, Telegram description or raw response in logs/UI.
        if ($ok===false) throw new TelegramApiError(0,true);
        try { $data=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new TelegramApiError($status,true); }
        if (!is_array($data) || !is_bool($data['ok']??null)) throw new TelegramApiError($status,true);
        if (!$data['ok']) { $code=is_int($data['error_code']??null)?$data['error_code']:$status; throw new TelegramApiError($code,$code>=500,min(3600,max(1,(int)($data['parameters']['retry_after']??3)))); }
        if ($status!==200 || (!is_array($data['result']??null) && !is_bool($data['result']??null))) throw new TelegramApiError($status,true);
        return $data['result'];
    }
}
