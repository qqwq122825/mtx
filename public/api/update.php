<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Packages,Releases,Security,Problem};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('GET');
$key=Http::text($_GET,'app',60);
$nonce=Http::text($_GET,'nonce',43);
if (!preg_match('/\A[A-Za-z0-9_-]{43}\z/',$nonce) || strlen(base64_decode(strtr($nonce,'-_','+/').'=',true)?:'')!==32) throw new Problem(422,'nonce 应为 32 字节随机数的 Base64URL 编码。');
$client=Packages::version(Http::text($_GET,'installer_version',12));
$os=Packages::version(Http::text($_GET,'os_version',12));
$profile=Http::text($_GET,'profile',80);
$installed=isset($_GET['installed_sequence'])?Http::integer($_GET,'installed_sequence'):null;
$s=$app->store->read(); $g=Releases::game($s,$key);
$status='no_release'; $message='此游戏暂无已发布版本。'; $wire=null;
if ($g['enabled'] && $g['current_release_id']) {
    $r=Releases::release($s,$g['current_release_id'],$key);
    if ($r['state']==='published') {
        if (version_compare($client,$r['min_installer_version'],'<') || $profile!==$r['profile_id']) { $status='client_upgrade_required'; $message='请先更新匹配本游戏的安装器。'; }
        elseif (version_compare($os,$r['min_ios'],'<') || version_compare($os,$r['max_ios'],'>')) { $status='incompatible'; $message='当前系统不在此版本的声明范围内。'; }
        else {
            $wire=Releases::wire($r);
            if ($installed===null || $installed>$r['sequence']) { $status='version_unknown'; $message='已安装版本待确认，请核对后选择安装。'; }
            elseif ($installed===$r['sequence']) { $status='up_to_date'; $message='已是当前发布版本。'; }
            else { $status='update_available'; $message='有新的发布版本。'; }
        }
    }
}
Http::json(Security::sign($app,['schema_version'=>1,'app_key'=>$key,'nonce'=>$nonce,'issued_at'=>gmdate('c'),'expires_at'=>gmdate('c',time()+600),'status'=>$status,'message'=>$message,'release'=>$wire]));
