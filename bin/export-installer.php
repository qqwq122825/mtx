<?php
// Public build binding only. Never exports a password or a signing secret.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$o=getopt('',['url:','out:']);
$c=require getenv('MTX_CONFIG')?:dirname(__DIR__).'/config.local.php';
$url=rtrim($o['url']??$c['base_url'],'/');
$p=parse_url($url);
if (!$p || ($p['scheme']??'')!=='https' || !isset($p['host']) || (isset($p['user']) || isset($p['pass'])) || !empty($p['path']) || isset($p['query']) || isset($p['fragment'])) throw new RuntimeException('Export requires HTTPS origin');
$values=['MTX_REMOTE_UPDATE_URL'=>$url.$c['mount_path'].'/api/update.php?app=mtx-dfm-cn','MTX_REMOTE_APP_KEY'=>'mtx-dfm-cn','MTX_REMOTE_PROFILE'=>'mtx-dfm-remote-v1','MTX_REMOTE_BUNDLE_ID'=>'com.mtx.scmtxdfm','MTX_REMOTE_KEY_ID'=>$c['key_id'],'MTX_REMOTE_PUBLIC_KEY'=>$c['native_sign_public']];
$out="// Generated public binding; the path is not an authentication credential.\n";
foreach($values as $key=>$value) $out.='#define '.$key.' @'.json_encode($value,JSON_UNESCAPED_SLASHES)."\n";
if (!isset($o['out']) || file_put_contents($o['out'],$out)===false) throw new RuntimeException('Use --out HEADER_PATH');
echo "Public binding exported. Signing secrets remain on server.\n";
