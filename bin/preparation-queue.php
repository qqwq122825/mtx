<?php
/** Private CLI only: no web route and no credential output. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
require dirname(__DIR__).'/vendor/autoload.php';
try {
    $app=new MTX\App(); $q=new MTX\Preparation($app);
    $input=json_decode(stream_get_contents(STDIN,8192),true,32,JSON_THROW_ON_ERROR);
    switch ($input['action']??'') {
        case 'location': $out=['work_root'=>$app->storage.'/preparation']; break;
        case 'claim': $out=$q->claim(); break;
        case 'finish': $q->finish($input['release_id'],$input['token'],$input['path']);$out=['ok'=>true];break;
        case 'fail': $q->fail($input['release_id'],$input['token'],$input['message']);$out=['ok'=>true];break;
        default: throw new RuntimeException('Unknown queue action');
    }
    echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),"\n";
} catch (Throwable $e) {
    fwrite(STDERR, ($e instanceof MTX\Problem ? $e->getMessage() : 'Preparation queue operation failed')."\n");exit(1);
}
