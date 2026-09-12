<?php
// Explicit local-only deployment export. Contains secrets: never publish or commit.
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$o=getopt('',['url:']);$url=rtrim($o['url']??'','/');
$p=parse_url($url);
if (!$p || ($p['scheme']??'')!=='https' || !isset($p['host']) || isset($p['user']) || isset($p['pass']) || !empty($p['path']) || isset($p['query']) || isset($p['fragment'])) throw new RuntimeException('Use --url https://HOST');
$root=dirname(__DIR__);$c=require getenv('MTX_CONFIG')?:$root.'/config.local.php';
$c['base_url']=$url;$c['local_http']=false;unset($c['storage']);
require __DIR__.'/package.php';
$private=$root.'/build/mtx-production-PRIVATE.zip';
if (!copy($root.'/build/mtx-update-center.zip',$private)) throw new RuntimeException('Archive copy failed');
chmod($private,0600);
$zip=new ZipArchive();if ($zip->open($private)!==true) throw new RuntimeException('Archive open failed');
$zip->addFromString('config.local.php',"<?php\n// PRIVATE. Do not expose through a web root or commit to Git.\nreturn ['storage'=>__DIR__.'/storage'] + ".var_export($c,true).";\n");
$zip->setExternalAttributesName('config.local.php',ZipArchive::OPSYS_UNIX,0100600<<16);
$zip->addEmptyDir('storage');$zip->addEmptyDir('storage/objects');$zip->addEmptyDir('storage/sessions');
foreach(['storage/','storage/objects/','storage/sessions/'] as $dir)$zip->setExternalAttributesName($dir,ZipArchive::OPSYS_UNIX,0040700<<16);
// Carry the game catalog and monotonic ID counter, but no releases/files/audit.
$catalog=(new MTX\Store((require getenv('MTX_CONFIG')?:$root.'/config.local.php')['storage']))->read();
MTX\GameIds::migrate($catalog);
$state=['schema'=>1,'next_game_id'=>$catalog['next_game_id'],'apps'=>$catalog['apps'],'releases'=>[],'audit'=>[]];
foreach ($state['apps'] as &$game) { $game['current_release_id']=null; $game['next_sequence']=1; $game['revision']=0; }
unset($game);
$zip->addFromString('storage/state.json',json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
$zip->setExternalAttributesName('storage/state.json',ZipArchive::OPSYS_UNIX,0100600<<16);
$nginx=str_replace('RANDOM_ENTRY',ltrim($c['mount_path'],'/'),file_get_contents($root.'/deploy/nginx.conf.example'));
$zip->addFromString('deploy/nginx.configured.conf',$nginx);
$readme="PRIVATE DEPLOYMENT — contains signing secrets and password hash.\n\n首次新站部署：解压至 /srv/mtx；Web 根目录只设 /srv/mtx/public。\n按 deploy/nginx.configured.conf 配置证书、PHP-FPM socket，开启 HTTPS 和 CDN。\nPHP-FPM 用户只需读取 config.local.php、写入 storage；确保属主和权限正确。\n不要重新运行 setup。不要放入 Git 或发到公开下载地址。旧站升级只替换代码，保留旧 config 和 storage。\n\n后台：".$url.$c['mount_path']."/admin/\n无用户名，使用已设置的管理密码。\n初始没有发布包，登录后上传 TIPA 和对应准备 TAR，完成测试再发布。\n本包与已导出的安装器公钥一致；请将私有包离线备份。\n";
$zip->addFromString('PRIVATE-DEPLOYMENT.txt',$readme);$zip->close();
echo $private."\n";
