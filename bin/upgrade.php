<?php
// One-time, offline v1 migration; preserves credentials, signing keys and releases.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
$path=getenv('MTX_CONFIG')?:dirname(__DIR__).'/config.local.php';
$config=require $path;
if (!isset($config['mount_path'])) $config['mount_path']='/r-'.bin2hex(random_bytes(12));
if (!isset($config['native_sign_secret'])) $config+=MTX\Security::nativeKeys();
$store=new MTX\Store($config['storage']);
$store->change(function (&$s) use ($config) {
    MTX\GameIds::migrate($s);
    foreach ($s['releases'] as &$r) if ($r['artifact_format']==='prepared-payload-v1' && $r['artifact_sha256'] && !isset($r['manifest_sha256'])) {
        $r['manifest_sha256']=MTX\Packages::prepared($config['storage'].'/objects/'.$r['artifact_sha256'],$r);
    }
});
MTX\Store::write($path,"<?php\n// Private: keep outside public and Git.\nreturn ".var_export($config,true).";\n");
echo $config['base_url'].$config['mount_path']."/admin/\n";
