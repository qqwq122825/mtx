<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,GameIds,Problem};
Http::method('GET','HEAD');
$id=Http::integer($_GET,'game_id');$state=$app->store->read();
GameIds::resolve($state,['game_id'=>$id]);
$installer=$state['home_installers'][(string)$id]??null;
if (!$installer) throw new Problem(404,'此游戏尚未开放安装器下载。');
$file=$app->object($installer['sha256']);
$handle=fopen($file,'rb');
if (!$handle) throw new Problem(404,'安装器文件暂未就绪。');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="MTX-Installer-'.$id.'.'.$installer['extension'].'"');
header('Content-Length: '.filesize($file));
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD']==='GET') fpassthru($handle);
fclose($handle);
