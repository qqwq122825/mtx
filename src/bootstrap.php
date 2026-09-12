<?php
declare(strict_types=1);
if (isset($GLOBALS['mtx_app'])) return $GLOBALS['mtx_app'];
require dirname(__DIR__) . '/vendor/autoload.php';
ini_set('display_errors','0');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e) {
    $id=bin2hex(random_bytes(8));
    $status=$e instanceof MTX\Problem?$e->status:500;
    $message=$e instanceof MTX\Problem?$e->getMessage():'服务暂时异常，请保留请求编号后重试。';
    if (!($e instanceof MTX\Problem)) error_log('MTX '.$id.' '.$e);
    http_response_code($status);
    header('Cache-Control: no-store');
    if (str_starts_with($GLOBALS['mtx_route']??'','/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error'=>['code'=>$e instanceof MTX\Problem?$e->kind:'server_error','message'=>$message],'request_id'=>$id],JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        try { $adminPath=($GLOBALS['mtx_app']??null)?->path('/admin/')??'/'; } catch (Throwable) { $adminPath='/'; }
        echo '<!doctype html><html lang="zh-CN"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MTX · 操作提示</title><link rel="stylesheet" href="'.MTX\Http::escape(($GLOBALS['mtx_app']??null)?->path('/assets/app.css')??'').'"><main class="error-page"><div class="eyebrow">MTX UPDATE CENTER</div><h1>本次操作未完成</h1><p>'.MTX\Http::escape($message).'</p><p class="muted">请求编号 '.MTX\Http::escape($id).'</p><a class="button" href="'.MTX\Http::escape($adminPath).'">返回后台</a></main></html>';
    }
});
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'; base-uri 'none'");
$app=new MTX\App();
$GLOBALS['mtx_app']=$app;
if (!$app->config['local_http']) header('Strict-Transport-Security: max-age=31536000');
return $app;
