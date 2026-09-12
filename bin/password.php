<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$path=getenv('MTX_CONFIG')?:dirname(__DIR__).'/config.local.php';
if (!is_file($path)) { fwrite(STDERR,"请先初始化。\n"); exit(1); }
$password=rtrim(stream_get_contents(STDIN),"\r\n");
if (strlen($password)<12 || strlen($password)>72) { fwrite(STDERR,"从标准输入传入 12–72 字节的新密码。\n"); exit(1); }
$config=require $path;
$config['password_hash']=password_hash($password,PASSWORD_DEFAULT);
MTX\Store::write($path,"<?php\nreturn ".var_export($config,true).";\n");
echo "密码已更新，旧会话将在下一次请求失效。\n";
