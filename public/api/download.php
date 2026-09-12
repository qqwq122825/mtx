<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Security,Releases,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET','HEAD');
$id=Http::text($_GET,'release',32); $sha=Http::text($_GET,'sha',64); $expires=Http::integer($_GET,'expires'); $token=Http::text($_GET,'token',64);
if ($expires<time() || $expires>time()+900 || !hash_equals(Security::ticket($app,$id,$sha,$expires),$token)) throw new Problem(403,'下载链接已过期或凭证无效。');
$s=$app->store->read(); $r=Releases::release($s,$id); $g=Releases::game($s,$r['app_key']);
if (!$g['enabled'] || $g['current_release_id']!==$id || $r['state']!=='published' || !in_array($sha,[$r['artifact_sha256'],$r['source_sha256']],true)) throw new Problem(404,'此版本已停止下载。');
$path=$app->object($sha);
$h=@fopen($path,'rb');
if (!$h) throw new Problem(404,'发布文件不存在。');
$stat=fstat($h); $size=$stat['size'];
if ($size!==($sha===$r['source_sha256']?$r['source_bytes']:$r['artifact_bytes'])) { fclose($h); throw new Problem(503,'发布文件长度异常。'); }
$etag='"'.$sha.'"'; $start=0; $end=$size-1; $status=200;
$range=$_SERVER['HTTP_RANGE']??'';
if ($range!=='' && (!isset($_SERVER['HTTP_IF_RANGE']) || $_SERVER['HTTP_IF_RANGE']===$etag)) {
    if (!preg_match('/\Abytes=(\d{0,12})-(\d{0,12})\z/',$range,$m) || ($m[1]==='' && $m[2]==='')) { fclose($h); header('Content-Range: bytes */'.$size); throw new Problem(416,'仅支持单个有效字节范围。'); }
    if ($m[1]==='') { $count=(int)$m[2]; $start=max(0,$size-$count); if ($count===0) $start=$size; }
    else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
    if ($start>$end || $start>=$size) { fclose($h); header('Content-Range: bytes */'.$size); throw new Problem(416,'字节范围超出文件。'); }
    $status=206; header("Content-Range: bytes $start-$end/$size");
}
http_response_code($status);
header('Content-Type: application/octet-stream'); header('Cache-Control: private, no-store'); header('Accept-Ranges: bytes'); header('ETag: '.$etag);
header('Content-Disposition: attachment; filename="game-'.$g['game_id'].'-'.$r['sequence'].($sha===$r['source_sha256']?'.tipa':'.tar').'"');
header('Content-Length: '.($end-$start+1));
if ($_SERVER['REQUEST_METHOD']==='HEAD') { fclose($h); exit; }
set_time_limit(0); fseek($h,$start); $left=$end-$start+1;
while ($left>0 && !connection_aborted()) { $chunk=fread($h,min(1048576,$left)); if ($chunk===false || $chunk==='') break; echo $chunk; $left-=strlen($chunk); }
fclose($h);
