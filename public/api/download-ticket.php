<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Releases,Security,Problem,GameIds};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('POST'); $body=Http::body();
$id=Http::text($body,'release_id',32); $sha=Http::text($body,'artifact_id',64);
$s=$app->store->read(); $g=GameIds::resolve($s,$body); $key=$g['app_key']; $r=Releases::release($s,$id,$key);
if (!$g['enabled'] || $g['current_release_id']!==$id || $r['state']!=='published' || !in_array($sha,[$r['artifact_sha256'],$r['source_sha256']],true)) throw new Problem(404,'版本已下架或不属于当前发布。');
if (!is_file($app->object($sha))) throw new Problem(503,'发布文件暂时不可用。');
$expiry=time()+900;
$query=http_build_query(['release'=>$id,'sha'=>$sha,'expires'=>$expiry,'token'=>Security::ticket($app,$id,$sha,$expiry)]);
Http::json(['game_id'=>$g['game_id'],'release_id'=>$id,'artifact_id'=>$sha,'url'=>$app->url('/api/download.php?'.$query),'expires_at'=>gmdate('c',$expiry)]);
