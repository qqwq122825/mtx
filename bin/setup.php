<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\Store;
try {
    $options=getopt('',['url:','storage:','config:','password-stdin','local-http','mount:']);
    $root=dirname(__DIR__);
    $path=$options['config']??$root.'/config.local.php';
    if (is_file($path)) throw new RuntimeException('配置已存在，setup 不覆盖现有配置。');
    $url=rtrim($options['url']??'', '/');
    $parts=parse_url($url);
    $local=isset($options['local-http']);
    if (!$parts || !isset($parts['host'],$parts['scheme']) || (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path']) || !in_array($parts['scheme'],['https','http'],true)) throw new RuntimeException('请设置 --url https://域名（独立域名根目录部署）。');
    if ($local && !in_array($parts['host'],['127.0.0.1','localhost','[::1]'],true)) throw new RuntimeException('local-http 仅用于本机地址。');
    if (!$local && $parts['scheme']!=='https') throw new RuntimeException('正式部署使用 HTTPS。');
    if (isset($options['password-stdin'])) $password=rtrim(stream_get_contents(STDIN),"\r\n");
    else {
        fwrite(STDOUT,'设置后台密码（至少 12 位）：');
        if (function_exists('system')) system('stty -echo');
        try { $password=rtrim(fgets(STDIN)?:'',"\r\n"); }
        finally { if (function_exists('system')) system('stty echo'); fwrite(STDOUT,"\n"); }
    }
    if (strlen($password)<12 || strlen($password)>72) throw new RuntimeException('密码长度需要 12–72 字节。');
    $storage=$options['storage']??$root.'/storage';
    if (!str_starts_with($storage,'/')) throw new RuntimeException('storage 使用绝对路径。');
    umask(0077);
    foreach ([$storage,$storage.'/objects',$storage.'/sessions'] as $dir) if (!is_dir($dir) && !mkdir($dir,0700,true)) throw new RuntimeException('创建存储目录失败。');
    if (str_starts_with(realpath($storage).'/',realpath($root.'/public').'/')) throw new RuntimeException('存储目录必须位于 public 之外。');
    if (is_file($storage.'/state.json')) throw new RuntimeException('已有状态文件，初始化停止以保护现有数据。');
    $mount=$options['mount']??'/r-'.bin2hex(random_bytes(12));
    if (!preg_match('/\A\/[a-z0-9-]{16,64}\z/D',$mount)) throw new RuntimeException('mount 应为 / 加 16–64 位小写字母、数字或连字符。');
    $pair=sodium_crypto_sign_keypair();
    $config=MTX\Security::nativeKeys()+['mount_path'=>$mount,'base_url'=>$url,'local_http'=>$local,'storage'=>realpath($storage),'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'max_upload_bytes'=>268435456,'key_id'=>'mtx-release-1','sign_secret'=>base64_encode(sodium_crypto_sign_secretkey($pair)),'sign_public'=>base64_encode(sodium_crypto_sign_publickey($pair)),'ticket_secret'=>bin2hex(random_bytes(32))];
    $state=['schema'=>1,'apps'=>['mtx-dfm-cn'=>['app_key'=>'mtx-dfm-cn','name'=>'满天星三角洲国服','bundle_id'=>'com.mtx.scmtxdfm','format'=>'prepared-payload-v1','profile_id'=>'mtx-dfm-remote-v1','enabled'=>true,'current_release_id'=>null,'next_sequence'=>1,'revision'=>0]],'releases'=>[],'audit'=>[]];
    MTX\GameIds::migrate($state);
    Store::write($storage.'/state.json',json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n");
    Store::write($path,"<?php\n// Private server-only configuration. Keep outside public and Git.\nreturn ".var_export($config,true).";\n");
    fwrite(STDOUT,"初始化完成：{$url}{$mount}/admin/\n配置：$path\n所有数据保存在：$storage\n");
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
