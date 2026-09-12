<?php
declare(strict_types=1);
if (!defined('MTX_FRONT_CONTROLLER')) { http_response_code(404); exit; }
use MTX\{Http,Security,Releases,Problem,Store};
$app=require dirname(__DIR__,2).'/src/bootstrap.php';
Http::method('POST'); Security::session($app);
$ajax=($_SERVER['HTTP_X_MTX_UPLOAD']??'')==='1';
try {
    if (!Security::loggedIn($app)) throw new Problem(401,'登录已过期，请重新登录。');
    Security::csrf();
    $action=Http::text($_POST,'action',30); $key=Http::text($_POST,'app_key',60);
    $service=new Releases($app);
    switch ($action) {
        case 'logout': $_SESSION=[]; session_destroy(); Http::redirect($app->path('/admin/login.php'));
        case 'create': $key=$service->createGame($_POST); $message='游戏配置已创建，固定地址已生成。'; break;
        case 'upload': $id=$service->upload($key,$app->upload('file','tipa'),$_POST); $message='TIPA 检查完成。请查看版本记录，准备完成后再发布。'; break;
        case 'attach': $service->attach($key,Http::text($_POST,'release_id',32),$app->upload('file','tar')); $message='准备产物的结构、身份和摘要校验通过，可确认发布。'; break;
        case 'publish':
            if (Http::text($_POST,'confirmed',1)!=='1') throw new Problem(422,'请先确认已完成对应安装器的兼容测试。');
            $service->publish($key,Http::text($_POST,'release_id',32),Http::integer($_POST,'revision')); $message='版本已发布，固定更新接口已切换。'; break;
        case 'withdraw': $service->withdraw($key,Http::text($_POST,'release_id',32),Http::integer($_POST,'revision')); $message='版本已下架，停止为此版本签发下载。'; break;
        case 'republish': $service->republishDraft($key,Http::text($_POST,'release_id',32)); $message='已创建历史内容的新草稿，发布时使用新的递增序号。'; break;
        case 'toggle': $service->toggle($key,Http::integer($_POST,'revision')); $message='游戏接口状态已更新。'; break;
        default: throw new Problem(422,'操作类型异常。');
    }
    $_SESSION['flash']=$message;
    $url=$app->path('/admin/?app=').rawurlencode($key);
    if ($ajax) Http::json(['redirect'=>$url,'message'=>$message]);
    Http::redirect($url);
} catch (Problem $e) {
    if ($ajax) Http::json(['error'=>['message'=>$e->getMessage()]],$e->status);
    throw $e;
}
