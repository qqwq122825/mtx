<?php
declare(strict_types=1);
namespace MTX;
final class Http
{
    public static function text(array $input,string $key,int $length=200,string $default=''): string
    {
        $value=$input[$key]??$default;
        if (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>$length || str_contains($value,"\0")) throw new Problem(422,'字段格式异常：'.$key);
        return $value;
    }
    public static function integer(array $input,string $key): int
    {
        $v=$input[$key]??null;
        if ((!is_int($v) && !is_string($v)) || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/',(string)$v)) throw new Problem(422,'数字字段异常：'.$key);
        return (int)$v;
    }
    public static function method(string ...$allowed): void
    {
        if (!in_array($_SERVER['REQUEST_METHOD']??'GET',$allowed,true)) { header('Allow: '.implode(', ',$allowed)); throw new Problem(405,'请求方法不匹配。'); }
    }
    public static function json(array $value,int $status=200): never
    { http_response_code($status); header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); echo json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR); exit; }
    public static function body(): array
    {
        if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') throw new Problem(415,'请发送 application/json。');
        $raw=file_get_contents('php://input',false,null,0,16385);
        if (strlen($raw)>16384) throw new Problem(413,'请求体过大。');
        try { $v=json_decode($raw,true,16,JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new Problem(422,'JSON 格式异常。'); }
        if (!is_array($v) || array_is_list($v)) throw new Problem(422,'请求体应为 JSON 对象。');
        return $v;
    }
    public static function redirect(string $path): never { header('Location: '.$path,true,303); exit; }
    public static function escape(mixed $s): string { return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
}
