<?php
/** All Telegram calls use an injected fake. No real bot, messages or external network. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\{App,Store,TelegramApi,TelegramApiError,TelegramBot,Problem};
$count=0;
function check(bool $ok,string $label): void { global $count; if (!$ok) throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label."\n"; }
function problem(callable $fn,int $status): bool { try {$fn();}catch(Problem $e){return $e->status===$status;}return false; }
function removeTree(string $dir): void { foreach(new FilesystemIterator($dir) as $p) { if($p->isDir()&&!$p->isLink())removeTree($p->getPathname());else unlink($p->getPathname()); }rmdir($dir); }
$dir=sys_get_temp_dir().'/mtx-telegram-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/objects');
$oldConfig=getenv('MTX_CONFIG');
try {
    Store::write($dir.'/state.json',json_encode(['schema'=>1,'next_game_id'=>2,'apps'=>['game-1'=>['game_id'=>1]],'releases'=>[]]));
    $config=['mount_path'=>'/r-telegram-fixture-0123456789','base_url'=>'http://127.0.0.1:8787','local_http'=>true,'storage'=>$dir];
    $path=$dir.'/config.php';Store::write($path,'<?php return '.var_export($config,true).';');putenv('MTX_CONFIG='.$path);
    $wire=[];$wireApi=new TelegramApi(function($method,$params,$json)use(&$wire){$wire[]=$json;return true;});
    $wireApi->call('fixture','getMe');$wireApi->call('fixture','setWebhook',['allowed_updates'=>['message']]);
    check($wire[0]==='{}','empty Telegram parameters encoded as an object, not an array');
    check($wire[1]==='{"allowed_updates":["message"]}','Telegram list parameters preserve JSON arrays');
    $calls=[];$next=100;$failure=null;
    $api=new TelegramApi(function($method,$params) use (&$calls,&$next,&$failure) {
        $calls[]=[$method,$params];
        if ($failure && $failure[0]===$method) { $e=$failure[1];$failure=null;throw $e; }
        return match($method) {'getMe'=>['is_bot'=>true,'username'=>'MTXFixtureBot'],'setWebhook','deleteWebhook'=>true,'getWebhookInfo'=>['url'=>'https://updates.example.com/r-telegram-fixture-0123456789/api/telegram-webhook.php','pending_update_count'=>2],default=>['message_id'=>++$next,'text'=>'RESPONSE_CONTENT_NOT_FOR_STORAGE']};
    });
    $bot=new TelegramBot(new App(),$api);$admin=77777;$a=88888;$b=99999;
    $token='123456:'.str_repeat('x',40);
    check(!$bot->store->read()['settings']['enabled'],'new bot starts unconfigured and paused');
    check(problem(fn()=>$bot->saveSettings(['token'=>'bad','admin_id'=>(string)$admin]),422),'invalid token rejected');
    foreach(['0','-100123','@owner','01','1.5',true] as $v)check(problem(fn()=>$bot->saveSettings(['token'=>$token,'admin_id'=>$v]),422),'invalid admin ID rejected: '.json_encode($v));
    $bot->saveSettings(['token'=>$token,'admin_id'=>(string)$admin]);$secret=$bot->store->read()['settings']['secret'];
    check(strlen($secret)===64 && count($calls)===0,'save generates private webhook secret without API calls');
    $bot->saveSettings(['token'=>'','admin_id'=>(string)$admin]);check($bot->store->read()['settings']['token']===$token,'blank token preserves stored secret');
    check(problem(fn()=>$bot->connect(),422) && count($calls)===0,'localhost never registers a webhook');
    $config['base_url']='https://updates.example.com';$config['local_http']=false;Store::write($path,'<?php return '.var_export($config,true).';');$bot=new TelegramBot(new App(),$api);
    $bot->connect();check(array_column($calls,0)===['getMe','sendMessage','setWebhook'],'connect validates bot, pings operator, registers webhook');
    check($calls[1][1]['chat_id']===$admin && $calls[2][1]['secret_token']===$secret && $calls[2][1]['max_connections']===1 && $calls[2][1]['allowed_updates']===['message'] && !$calls[2][1]['drop_pending_updates'],'webhook secret, private admin and constrained updates');
    check($bot->store->read()['settings']['enabled'] && $bot->store->read()['settings']['username']==='MTXFixtureBot','successful activation saved');
    check(problem(fn()=>$bot->saveSettings(['token'=>$token,'admin_id'=>'55555']),409),'active binding cannot be replaced');
    check($bot->status()===['matches'=>true,'pending'=>2,'has_error'=>false],'connection status only returns sanitized fields');
    $pair=['message'=>['chat'=>['type'=>'private','id'=>$admin],'from'=>['id'=>$admin,'is_bot'=>false],'text'=>'/id random-challenge','date'=>time()]];
    check(TelegramBot::findPairingID([$pair],'/id random-challenge')===$admin,'new-bot ID helper matches exact private command');
    check(TelegramBot::findPairingID([$pair],'/id wrong')===null,'ID helper ignores unrelated messages');
    $forward=$pair;$forward['message']['forward_origin']=['type'=>'hidden_user'];
    check(TelegramBot::findPairingID([$forward],'/id random-challenge')===null,'ID helper ignores forwarded pairing text');
    $otherPair=$pair;$otherPair['message']['chat']['id']=$b;$otherPair['message']['from']['id']=$b;
    check(TelegramBot::findPairingID([$pair,$otherPair],'/id random-challenge')===null,'ID helper rejects ambiguous users');
    $uid=1;
    $message=function(int $chat,array $extra=[]) use (&$uid):array { $id=$uid++;return ['update_id'=>$id,'message'=>array_replace_recursive(['message_id'=>$id+5000,'date'=>time(),'chat'=>['id'=>$chat,'type'=>'private'],'from'=>['id'=>$chat,'is_bot'=>false,'first_name'=>'FIXTURE_PERSONAL_NAME'],'text'=>'PRIVATE_USER_TEXT_NOT_FOR_STORAGE'],$extra)]; };
    $deliver=function(array $u) use ($bot,$secret,&$calls):array { $n=count($calls);$bot->receive($u,$secret);return array_slice($calls,$n); };
    check(problem(fn()=>$bot->receive($message($a),'wrong'),403),'forged webhook secret rejected');
    check(problem(fn()=>$bot->receive(['update_id'=>'1'],$secret),422),'update ID is strictly integer');
    $out=$deliver($message($a,['text'=>'/start']));check(count($out)===1 && $out[0][1]['chat_id']===$a && str_contains($out[0][1]['text'],'管理员'),'user welcome explains relay');
    $out=$deliver($message($a,['text'=>'/id']));check(str_contains($out[0][1]['text'],(string)$a),'id command returns caller only');
    $u=$message($a);$out=$deliver($u);$routes=$bot->store->read()['routes'];$aCopy=array_key_last($routes);$aHeading=$aCopy-1;
    check(array_column($out,0)===['sendMessage','copyMessage'],'incoming user message gets header and copy');
    check($out[1][1]['chat_id']===$admin && $out[1][1]['from_chat_id']===$a && $out[1][1]['message_id']===$u['message']['message_id'],'correct incoming source and destination');
    check($routes[$aCopy]['chat_id']===$a && $routes[$aHeading]['chat_id']===$a && $out[1][1]['reply_parameters']['message_id']===$aHeading,'header and message both mapped to user');
    check($deliver($u)===[],'duplicate webhook does not resend');
    $reply=$message($admin,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'ADMIN_PRIVATE_REPLY']);$out=$deliver($reply);
    check($out[0][0]==='copyMessage' && $out[0][1]['chat_id']===$a && $out[0][1]['from_chat_id']===$admin && $out[0][1]['message_id']===$reply['message']['message_id'],'operator replies via bot copy to mapped user');
    check($out[1][1]['chat_id']===$admin && str_contains($out[1][1]['text'],'已回复'),'operator delivery receipt');
    $deliver($message($b));$bCopy=array_key_last($bot->store->read()['routes']);
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$bCopy]]));check($out[0][1]['chat_id']===$b,'separate users keep separate reply routes');
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$aHeading]]));check($out[0][1]['chat_id']===$a,'reply to original header also routes correctly');
    $out=$deliver($message($b,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'/block']));check($out[1][1]['chat_id']===$admin && !$bot->store->read()['blocked'],'non-operator cannot invoke moderation or redirect another user');
    $out=$deliver($message($admin));check(count($out)===1 && str_contains($out[0][1]['text'],'回复'),'operator must reply to known message');
    foreach ([['chat'=>['type'=>'group']],['from'=>['is_bot'=>true]],['from'=>['id'=>123]],['date'=>time()-90000]] as $bad) check($deliver($message($admin,$bad))===[],'non-private, bot, forged sender and stale messages ignored');
    check($deliver(['update_id'=>$uid++,'edited_message'=>['text'=>'edit']])===[],'edited/channel updates ignored');
    foreach (['photo','document','voice','video','sticker'] as $kind) { $u=$message(44444,[$kind=>['file_id'=>'fixture']]);unset($u['message']['text']);$out=$deliver($u);check($out[1][0]==='copyMessage','relay without server file download: '.$kind); }
    $u=$message($b,['invoice'=>[]]);unset($u['message']['text']);$out=$deliver($u);check(count($out)===1 && $out[0][1]['chat_id']===$b,'unsupported service/payment content gets a user hint');
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'/who']));check(str_contains($out[0][1]['text'],(string)$a),'operator can inspect reply target');
    $deliver($message($admin,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'/block']));check(isset($bot->store->read()['blocked'][$a]),'operator can block a mapped user');
    check($deliver($message($a))===[],'blocked user is filtered before any send');
    $out=$deliver($message($admin,['text'=>'/unblock 9999999999999999']));check(count($out)===1 && str_contains($out[0][1]['text'],'正确'),'invalid moderation ID gets a hint rather than endless webhook retry');
    $deliver($message($admin,['text'=>'/unblock '.$a]));check(!isset($bot->store->read()['blocked'][$a]),'operator can unblock by numeric ID');
    check(count($deliver($message($a)))===2,'unblocked user can contact support again');
    for($i=0;$i<10;$i++)$deliver($message(33333));check($deliver($message(33333))===[],'per-user rate limit filters the eleventh message in a minute');
    $failure=['copyMessage',new TelegramApiError(403)];$u=$message($admin,['reply_to_message'=>['message_id'=>$bCopy]]);$out=$deliver($u);$state=$bot->store->read();
    check($state['updates'][$u['update_id']]['status']==='failed' && end($state['events'])['peer']===$b,'blocked-recipient failure records actual user, not operator');
    check(str_contains(end($out)[1]['text'],'#'.$b) && $deliver($u)===[],'permanent failure notifies operator once and is not replayed');
    $failure=['copyMessage',new TelegramApiError(429,false,1)];$u=$message(22222);$n=count($calls);
    check(problem(fn()=>$bot->receive($u,$secret),503),'known Telegram rate limit asks webhook to retry');
    $headingCount=count(array_filter(array_slice($calls,$n),fn($v)=>$v[0]==='sendMessage'));
    check(problem(fn()=>$bot->receive($u,$secret),503) && count($calls)===$n+2,'retry-after window performs no new outbound calls');
    $bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]['retry_at']=0;$bot->store->save($s);});$out=$deliver($u);
    check($headingCount===1 && count($out)===1 && $out[0][0]==='copyMessage','429 retry resumes incomplete step without duplicating header');
    $failure=['copyMessage',new TelegramApiError(0,true)];$u=$message(11111);$out=$deliver($u);check($bot->store->read()['updates'][$u['update_id']]['status']==='uncertain' && $deliver($u)===[],'network ambiguity marked for review, never blindly resent');
    $u=$message(11112);$bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]=['at'=>time(),'status'=>'working','steps'=>['heading'=>['status'=>'pending']]];$bot->store->save($s);});$out=$deliver($u);
    check(count($out)===1 && str_contains($out[0][1]['text'],'待确认') && $bot->store->read()['updates'][$u['update_id']]['status']==='uncertain','crash after intent journal does not duplicate an uncertain send');
    $bot->store->locked(function(&$s)use($bot,$aCopy){$s['routes'][$aCopy]['at']=time()-31*86400;$bot->store->save($s);});$out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$aCopy]]));check(count($out)===1 && $out[0][0]==='sendMessage','expired reply mapping never routes to a user');
    $raw=file_get_contents($bot->store->directory.'/state.json');check(!str_contains($raw,'PRIVATE_USER_TEXT')&&!str_contains($raw,'FIXTURE_PERSONAL_NAME')&&!str_contains($raw,'RESPONSE_CONTENT')&&!str_contains($raw,'ADMIN_PRIVATE_REPLY'),'storage contains no message body, response body or display name');
    check((fileperms($bot->store->directory.'/state.json')&0777)===0600 && (fileperms($bot->store->directory)&0777)===0700,'private file and directory modes');
    if (function_exists('pcntl_fork')) {
        $u=$message(161616);$log=$dir.'/concurrent.log';$pids=[];
        for($i=0;$i<2;$i++){ $pid=pcntl_fork();if($pid===0){$counter=9000;$api2=new TelegramApi(function($method,$params)use($log,&$counter){file_put_contents($log,$method."\n",FILE_APPEND|LOCK_EX);usleep(30000);return ['message_id'=>++$counter];});(new TelegramBot(new App(),$api2))->receive($u,$secret);exit(0);}$pids[]=$pid; }
        foreach($pids as $pid){pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'concurrent worker completed');}
        check(file($log,FILE_IGNORE_NEW_LINES)===['sendMessage','copyMessage'],'concurrent duplicate updates forwarded exactly once in normal execution');
    }
    $failure=['deleteWebhook',new TelegramApiError(0,true)];try{$bot->disconnect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'pause is local-first even if remote removal is uncertain');
    check($deliver($message($b))===[],'paused valid webhook does not send');
    $bot->saveSettings(['token'=>'123456:'.str_repeat('y',40),'admin_id'=>'55555']);$state=$bot->store->read();check(!$state['routes']&&!$state['updates']&&!$state['blocked']&&$state['settings']['secret']!==$secret,'new bot/operator clears prior identity mappings and rotates webhook secret');
    check(problem(fn()=>$bot->checkSecret($secret),403),'old webhook secret invalidated');
    $failure=['sendMessage',new TelegramApiError(403)];try{$bot->connect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'operator must start bot before activation');
    $badRegister=new TelegramApi(fn($method,$params)=>match($method){'getMe'=>['is_bot'=>true,'username'=>'MTXFixtureBot'],'sendMessage'=>['message_id'=>999],default=>false});
    try{(new TelegramBot(new App(),$badRegister))->connect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'unsuccessful registration never displays enabled');
    echo "\n$count Telegram checks passed; fake API only.\n";
} finally { if($oldConfig===false)putenv('MTX_CONFIG');else putenv('MTX_CONFIG='.$oldConfig);removeTree($dir); }
