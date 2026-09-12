<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Problem,TelegramBot};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('POST');
$bot=new TelegramBot($app);$secret=$_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'';
$bot->checkSecret($secret);
if (strtolower(trim(explode(';',$_SERVER['CONTENT_TYPE']??'')[0]))!=='application/json') throw new Problem(415,'请发送 application/json。');
if ((int)($_SERVER['CONTENT_LENGTH']??0)>262144) throw new Problem(413,'Webhook 请求体过大。');
$raw=file_get_contents('php://input',false,null,0,262145);
if (strlen($raw)>262144) throw new Problem(413,'Webhook 请求体过大。');
try { $update=json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException) { throw new Problem(422,'Webhook JSON 格式异常。'); }
if (!is_array($update) || array_is_list($update)) throw new Problem(422,'Webhook 应为 JSON 对象。');
$bot->receive($update,$secret);
Http::json(['ok'=>true]);
