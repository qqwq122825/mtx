<?php
declare(strict_types=1);
use MTX\{Http,Releases,Security,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('POST'); $body=Http::body();
$key=Http::text($body,'app_key',60); $id=Http::text($body,'release_id',32); $sha=Http::text($body,'artifact_id',64);
$s=$app->store->read(); $g=Releases::game($s,$key); $r=Releases::release($s,$id,$key);
if (!$g['enabled'] || $g['current_release_id']!==$id || $r['state']!=='published' || $r['artifact_sha256']!==$sha) throw new Problem(404,'版本已下架或不属于当前发布。');
if (!is_file($app->object($sha))) throw new Problem(503,'发布文件暂时不可用。');
$expiry=time()+900;
$query=http_build_query(['release'=>$id,'sha'=>$sha,'expires'=>$expiry,'token'=>Security::ticket($app,$id,$sha,$expiry)]);
Http::json(['app_key'=>$key,'release_id'=>$id,'artifact_id'=>$sha,'url'=>$app->url('/api/download.php?'.$query),'expires_at'=>gmdate('c',$expiry)]);
