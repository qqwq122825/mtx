<?php
/** All Telegram calls use an injected fake. No real bot, messages or external network. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\{App,Store,TelegramApi,TelegramApiError,TelegramBot,TelegramReplies,Problem};
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
    $calls=[];$next=100;$failure=null;$profiles=[];
    $api=new TelegramApi(function($method,$params) use (&$calls,&$next,&$failure,&$profiles) {
        $calls[]=[$method,$params];
        if ($failure && $failure[0]===$method && (!isset($failure[2]) || ($params['chat_id']??null)===$failure[2])) { $e=$failure[1];$failure=null;throw $e; }
        return match($method) {'getChat'=>$profiles[$params['chat_id']]??['id'=>$params['chat_id'],'type'=>'private','first_name'=>'FIXTURE_LOOKUP_NAME','username'=>'FixtureRecipient_'.$params['chat_id']], 'getMe'=>['is_bot'=>true,'username'=>'MTXFixtureBot'],'setWebhook','deleteWebhook','answerCallbackQuery'=>true,'getWebhookInfo'=>['url'=>'https://updates.example.com/r-telegram-fixture-0123456789/api/telegram-webhook.php','pending_update_count'=>2,'allowed_updates'=>['message','callback_query']],default=>['message_id'=>++$next,'text'=>'RESPONSE_CONTENT_NOT_FOR_STORAGE']};
    });
    $bot=new TelegramBot(new App(),$api);$admin=77777;$a=88888;$b=99999;
    $token='123456:'.str_repeat('x',40);
    check(!$bot->store->read()['settings']['enabled'],'new bot starts unconfigured and paused');
    check(problem(fn()=>$bot->saveSettings(['token'=>'bad','admin_id'=>(string)$admin]),422),'invalid token rejected');
    foreach(['0','-100123','@owner','01','1.5',true] as $v)check(problem(fn()=>$bot->saveSettings(['token'=>$token,'admin_id'=>$v]),422),'invalid admin ID rejected: '.json_encode($v));
    $bot->saveSettings(['token'=>$token,'admin_id'=>(string)$admin]);$secret=$bot->store->read()['settings']['secret'];
    check(strlen($secret)===64 && count($calls)===0,'save generates private webhook secret without API calls');
    $bot->saveSettings(['token'=>'','admin_id'=>(string)$admin]);check($bot->store->read()['settings']['token']===$token,'blank token preserves stored secret');
    check(problem(fn()=>$bot->refreshWebhook(),422) && count($calls)===0,'localhost cannot refresh webhook subscriptions');
    check(problem(fn()=>$bot->connect(),422) && count($calls)===0,'localhost never registers a webhook');
    $config['base_url']='https://updates.example.com';$config['local_http']=false;Store::write($path,'<?php return '.var_export($config,true).';');$bot=new TelegramBot(new App(),$api);
    $bot->connect();check(array_column($calls,0)===['getMe','sendMessage','setWebhook'],'connect validates bot, pings operator, registers webhook');
    check($calls[1][1]['chat_id']===$admin && $calls[2][1]['secret_token']===$secret && $calls[2][1]['max_connections']===1 && $calls[2][1]['allowed_updates']===['message','callback_query'] && !$calls[2][1]['drop_pending_updates'],'webhook secret, private admin and constrained updates');
    check($bot->store->read()['settings']['enabled'] && $bot->store->read()['settings']['username']==='MTXFixtureBot','successful activation saved');
    check(problem(fn()=>$bot->saveSettings(['token'=>$token,'admin_id'=>'55555']),409),'active binding cannot be replaced');
    check($bot->status()===['matches'=>true,'pending'=>2,'has_error'=>false,'callbacks'=>true],'connection status only returns sanitized fields');
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
    $out=$deliver($message($a,['text'=>'/start']));check(count($out)===1 && $out[0][1]['chat_id']===$a && str_contains($out[0][1]['text'],'看到消息后') && isset($out[0][1]['reply_markup']['inline_keyboard']) && !isset($out[0][1]['reply_markup']['keyboard']),'user welcome includes reply choices');
    $out=$deliver($message($a,['text'=>'/id']));check(str_contains($out[0][1]['text'],(string)$a),'id command returns caller only');
    $settings=$bot->store->read()['settings'];$defaults=TelegramReplies::defaults();
    check(TelegramReplies::read($bot->store->read())===$defaults,'existing state uses default replies without migration');
    $legacy=['welcome'=>'Existing welcome','consult'=>'Old project','install'=>'Old install','support'=>'Old support','received'=>'Existing receipt'];
    $current=TelegramReplies::read(['auto_replies'=>$legacy]);
    check(array_keys($current)===['welcome','question','cooperation','received'] && $current['welcome']===$legacy['welcome'] && $current['received']===$legacy['received'] && $current['question']===$defaults['question'] && $current['cooperation']===$defaults['cooperation'],'old templates retain welcome and receipt without leaking removed categories');
    $bot->saveReplies($current+$legacy);
    check($bot->store->read()['auto_replies']===$current && $bot->store->read()['settings']===$settings,'saving current templates drops obsolete fields and preserves enabled binding');
    $bot->saveReplies($defaults);
    foreach (TelegramReplies::BUTTONS as $button=>$field) {
        $out=$deliver($message(121212,['text'=>$button]));
        check(count($out)===1 && $out[0][1]['text']===$defaults[$field] && $out[0][1]['chat_id']===121212,'quick reply only goes to the requesting user: '.$field);
    }
    $out=$deliver($message($admin,['text'=>'/start']));check($out[0][1]['text']===$defaults['welcome'],'operator can preview the same welcome');
    $out=$deliver($message($admin,['text'=>'/help']));check(str_contains($out[0][1]['text'],'/block') && ($out[0][1]['reply_markup']['remove_keyboard']??false),'operator help keeps moderation instructions');
    $custom=$defaults;$custom['welcome']="CUSTOM welcome <tag>\nSecond line";
    $bot->saveReplies($custom+['token'=>'ignored','admin_id'=>'123']);
    check($bot->store->read()['settings']===$settings,'editing replies while enabled preserves binding and webhook secret');
    $out=$deliver($message(121212,['text'=>'/start']));check($out[0][1]['text']===$custom['welcome'] && !isset($out[0][1]['parse_mode']),'custom welcome is plain text and immediate');
    foreach (['',str_repeat('字',1001),['bad'],"bad\0text"] as $bad) {
        check(problem(fn()=>$bot->saveReplies(array_replace($defaults,['welcome'=>$bad])),422),'invalid template rejected atomically');
        check(TelegramReplies::read($bot->store->read())===$custom,'invalid edit leaves prior templates intact');
    }
    $bot->saveReplies($defaults);
    $u=$message($a,['from'=>['username'=>'FIXTURE_PERSONAL_USERNAME'],'chat'=>['username'=>'WrongChatName'],'forward_origin'=>['type'=>'user','sender_user'=>['username'=>'WrongForwardedAuthor']]]);$out=$deliver($u);$routes=$bot->store->read()['routes'];$aCopy=array_key_last($routes);$aHeading=$aCopy-1;
    check(array_column($out,0)===['sendMessage','copyMessage','sendMessage'] && $out[2][1]['chat_id']===$a,'incoming user message gets header, copy and user acknowledgement');
    check($out[0][1]['chat_id']===$admin && $out[0][1]['text']==='📩 @FIXTURE_PERSONAL_USERNAME 发来新消息'."\n昵称：FIXTURE_PERSONAL_NAME · ID：".$a."\n↩️ 回复这张卡片或下方消息即可回信。" && !isset($out[0][1]['parse_mode']),'operator card shows actual sender handle, name and ID as plain text, not forwarded/chat username');
    check($out[1][1]['chat_id']===$admin && $out[1][1]['from_chat_id']===$a && $out[1][1]['message_id']===$u['message']['message_id'],'correct incoming source and destination');
    check($out[0][1]['disable_notification']===false && $out[1][1]['disable_notification']===true,'new inquiry alerts through the identity card, body copy is silent');
    check($routes[$aCopy]['chat_id']===$a && $routes[$aHeading]['chat_id']===$a && $out[1][1]['reply_parameters']['message_id']===$aHeading,'header and message both mapped to user');
    check($deliver($u)===[],'duplicate webhook does not resend');
    $out=$deliver($message(131313));check(count($out)===3,'first issue gets one receipt');
    check(str_contains($out[0][1]['text'],"\n未设置用户名 · ID："),'sender without a username is identified explicitly');
    $out=$deliver($message(131313));check(count($out)===2,'follow-up within cooldown still forwards without receipt');
    $bot->store->locked(function(&$s)use($bot){$s['reply_receipts'][131313]['at']=time()-TelegramReplies::COOLDOWN-1;$bot->store->save($s);});
    $out=$deliver($message(131313));check(count($out)===3,'receipt becomes eligible after cooldown');
    $failure=['copyMessage',new TelegramApiError(403)];$out=$deliver($message(141414));
    check(!array_filter($out,fn($call)=>($call[1]['chat_id']??0)===141414) && !isset($bot->store->read()['reply_receipts'][141414]),'failed incoming copy never tells user message was received');
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'💬 问题咨询']));
    check($out[0][0]==='copyMessage' && $out[0][1]['chat_id']===$a,'operator reply matching menu text still reaches user');
    $reply=$message($admin,['reply_to_message'=>['message_id'=>$aCopy],'text'=>'ADMIN_PRIVATE_REPLY']);$out=$deliver($reply);
    check($out[0][0]==='copyMessage' && $out[0][1]['chat_id']===$a && $out[0][1]['from_chat_id']===$admin && $out[0][1]['message_id']===$reply['message']['message_id'],'operator replies via bot copy to mapped user');
    check(array_column($out,0)===['copyMessage','getChat','sendMessage'] && $out[1][1]['chat_id']===$a && $out[2][1]['chat_id']===$admin && $out[2][1]['text']==='✅ 已回复 @FixtureRecipient_'.$a.'（#'.$a.'）','operator receipt names the current recipient after confirmed delivery');
    check(!($out[0][1]['disable_notification']??false) && $out[2][1]['disable_notification']===true,'user still receives a normal reply while operator success notice is silent');
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
    check($headingCount===1 && count($out)===2 && $out[0][0]==='copyMessage' && $out[1][1]['chat_id']===22222,'429 retry resumes incomplete step without duplicating header');
    $failure=['sendMessage',new TelegramApiError(429,false,1),232323];$u=$message(232323);
    check(problem(fn()=>$bot->receive($u,$secret),503),'receipt 429 schedules retry after a confirmed forward');
    check($bot->store->read()['updates'][$u['update_id']]['receipt_due'] && isset($bot->store->read()['reply_receipts'][232323]),'receipt intent and cooldown survive rate limit');
    $out=$deliver($message(232323));check(count($out)===2,'another message during receipt retry does not repeat receipt');
    $bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]['retry_at']=0;$bot->store->save($s);});
    $out=$deliver($u);check(count($out)===1 && $out[0][1]['chat_id']===232323 && $deliver($u)===[],'receipt retry only completes receipt, not the already copied message');
    $failure=['sendMessage',new TelegramApiError(429,false,1),232324];$oldReceipt=$message(232324);
    check(problem(fn()=>$bot->receive($oldReceipt,$secret),503),'long receipt retry starts with known 429');
    $bot->store->locked(function(&$s)use($bot,$oldReceipt){$s['updates'][$oldReceipt['update_id']]['retry_at']=0;$s['reply_receipts'][232324]['at']=time()-TelegramReplies::COOLDOWN-1;$bot->store->save($s);});
    check(count($deliver($message(232324)))===3,'new contact after expired reservation may receive fresh receipt');
    check($deliver($oldReceipt)===[],'very late old receipt retry yields to a newer receipt');
    $failure=['sendMessage',new TelegramApiError(0,true),242424];$u=$message(242424);$out=$deliver($u);
    check($bot->store->read()['updates'][$u['update_id']]['status']==='uncertain' && $deliver($u)===[],'ambiguous receipt is not automatically resent');
    check(count($deliver($message(242424)))===2,'ambiguous receipt retains cooldown but later user content still forwards');
    $receipts=$bot->store->read()['reply_receipts'];
    $bot->store->locked(function(&$s)use($bot){$s['reply_receipts']=array_fill_keys(range(500000,509999),['at'=>time(),'update_id'=>0]);$bot->store->save($s);});
    check(count($deliver($message(252525)))===2 && count($bot->store->read()['reply_receipts'])===10000,'full receipt map skips optional receipt without blocking message forwarding');
    $bot->store->locked(function(&$s)use($bot,$receipts){$s['reply_receipts']=$receipts;$bot->store->save($s);});
    $failure=['copyMessage',new TelegramApiError(0,true)];$u=$message(11111);$out=$deliver($u);check($bot->store->read()['updates'][$u['update_id']]['status']==='uncertain' && $deliver($u)===[],'network ambiguity marked for review, never blindly resent');
    $u=$message(11112);$bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]=['at'=>time(),'status'=>'working','steps'=>['heading'=>['status'=>'pending']]];$bot->store->save($s);});$out=$deliver($u);
    check(count($out)===1 && str_contains($out[0][1]['text'],'待确认') && $bot->store->read()['updates'][$u['update_id']]['status']==='uncertain','crash after intent journal does not duplicate an uncertain send');
    $bot->store->locked(function(&$s)use($bot,$aCopy){$s['routes'][$aCopy]['at']=time()-31*86400;$bot->store->save($s);});$out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$aCopy]]));check(count($out)===1 && $out[0][0]==='sendMessage','expired reply mapping never routes to a user');
    $identityPeer=282800;
    foreach (['',null,[],true,123,'@WrongName',"Injected\n@Other",'Wrong<Name>',str_repeat('x',65),"Bidi\u{202e}Name"] as $bad) {
        $out=$deliver($message(++$identityPeer,['from'=>['username'=>$bad]]));
        check(str_contains($out[0][1]['text'],"\n未设置用户名 · ID：") && $out[1][0]==='copyMessage','invalid handle is omitted without blocking relay: '.json_encode($bad));
    }
    foreach (['Ab_c',str_repeat('X',64)] as $valid) {
        $out=$deliver($message(++$identityPeer,['from'=>['username'=>$valid]]));
        check(str_contains($out[0][1]['text'],'📩 @'.$valid.' 发来新消息'),'handle case and short or long valid values are preserved');
    }
    $out=$deliver($message(282900,['from'=>['first_name'=>"名字\n\u{2028}\u{202e}冒充",'username'=>'Current_User']]));$oldCard=$out[1][1]['reply_parameters']['message_id'];
    check(substr_count($out[0][1]['text'],"\n")===2 && !str_contains($out[0][1]['text'],"\u{202e}") && str_contains($out[0][1]['text'],'📩 @Current_User 发来新消息'),'display-name line and bidi controls do not corrupt the separate handle row');
    $out=$deliver($message(282900,['from'=>['username'=>'Renamed_User']]));
    check(str_contains($out[0][1]['text'],'📩 @Renamed_User 发来新消息'),'new incoming card immediately reflects changed sender username');
    $profiles[282900]=['id'=>282900,'type'=>'private','username'=>'Renamed_User','first_name'=>'Changed_Name'];
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$oldCard]]));
    check($out[0][0]==='copyMessage' && $out[0][1]['chat_id']===282900,'reply to an earlier card still uses the same numeric ID after a username change');
    check($out[2][1]['text']==='✅ 已回复 @Renamed_User（#282900）' && !isset($out[2][1]['parse_mode']),'success notice uses current live handle, not stale card text or parsed markup');
    $profiles[282900]=['id'=>282900,'type'=>'private','first_name'=>"名字\n\u{202e}后缀"];
    $out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$oldCard]]));
    check($out[2][1]['text']==='✅ 已回复 名字  后缀（#282900）','receipt without a handle uses a sanitized nickname and numeric ID');
    foreach ([true,[],['id'=>123,'type'=>'private','username'=>'WrongIdentity'],['id'=>282900,'type'=>'group','username'=>'WrongIdentity'],['id'=>'282900','type'=>'private','username'=>'WrongIdentity']] as $badProfile) {
        $profiles[282900]=$badProfile;$out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$oldCard]]));
        check($out[2][1]['text']==='✅ 已回复 用户 #282900','malformed or mismatched chat lookup falls back to the routed numeric ID');
    }
    unset($profiles[282900]);
    foreach ([new TelegramApiError(403),new TelegramApiError(429,false,30),new TelegramApiError(0,true)] as $lookupFailure) {
        $failure=['getChat',$lookupFailure];$u=$message($admin,['reply_to_message'=>['message_id'=>$oldCard]]);$out=$deliver($u);
        check(array_column($out,0)===['copyMessage','getChat','sendMessage'] && $out[2][1]['text']==='✅ 已回复 用户 #282900' && $bot->store->read()['updates'][$u['update_id']]['status']==='done' && $deliver($u)===[],'optional profile lookup failure preserves confirmed delivery without retrying the reply');
    }
    $failure=['copyMessage',new TelegramApiError(403)];$out=$deliver($message($admin,['reply_to_message'=>['message_id'=>$oldCard]]));
    check(!in_array('getChat',array_column($out,0),true) && !array_filter($out,fn($call)=>str_starts_with($call[1]['text']??'','✅ 已回复')),'failed outgoing copy never fetches a label or claims success');
    $failure=['sendMessage',new TelegramApiError(429,false,1),$admin];$u=$message($admin,['reply_to_message'=>['message_id'=>$oldCard]]);
    check(problem(fn()=>$bot->receive($u,$secret),503),'operator success notice rate limit uses existing retry journal');
    $bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]['retry_at']=0;$bot->store->save($s);});
    $out=$deliver($u);
    check(array_column($out,0)===['getChat','sendMessage'] && $out[1][1]['disable_notification']===true && $deliver($u)===[],'success notice retry remains silent and never duplicates the delivered reply');
    $raw=file_get_contents($bot->store->directory.'/state.json');check(!str_contains($raw,'PRIVATE_USER_TEXT')&&!str_contains($raw,'FIXTURE_PERSONAL_NAME')&&!str_contains($raw,'FIXTURE_PERSONAL_USERNAME')&&!str_contains($raw,'FIXTURE_LOOKUP_NAME')&&!str_contains($raw,'FixtureRecipient_')&&!str_contains($raw,'Renamed_User')&&!str_contains($raw,'RESPONSE_CONTENT')&&!str_contains($raw,'ADMIN_PRIVATE_REPLY'),'storage contains no message body, response body, display name or sender handle');
    check((fileperms($bot->store->directory.'/state.json')&0777)===0600 && (fileperms($bot->store->directory)&0777)===0700,'private file and directory modes');
    if (function_exists('pcntl_fork')) {
        $u=$message(161616);$log=$dir.'/concurrent.log';$pids=[];
        for($i=0;$i<2;$i++){ $pid=pcntl_fork();if($pid===0){$counter=9000;$api2=new TelegramApi(function($method,$params)use($log,&$counter){file_put_contents($log,$method."\n",FILE_APPEND|LOCK_EX);usleep(30000);return ['message_id'=>++$counter];});(new TelegramBot(new App(),$api2))->receive($u,$secret);exit(0);}$pids[]=$pid; }
        foreach($pids as $pid){pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'concurrent worker completed');}
        check(file($log,FILE_IGNORE_NEW_LINES)===['sendMessage','copyMessage','sendMessage'],'concurrent duplicate updates forwarded and acknowledged exactly once in normal execution');
    }
    $before=$bot->store->read();$n=count($calls);$bot->refreshWebhook();$newCalls=array_slice($calls,$n);
    check(count($newCalls)===1 && $newCalls[0][0]==='setWebhook' && $newCalls[0][1]['allowed_updates']===['message','callback_query'] && !$newCalls[0][1]['drop_pending_updates'],'subscription refresh adds card callbacks without dropping pending updates');
    check($bot->store->read()===$before,'subscription refresh leaves enabled binding, templates, routes and state intact');
    $card=TelegramReplies::keyboard();$buttons=array_merge(...$card['inline_keyboard']);
    check(count($card['inline_keyboard'])===1 && count($buttons)===2 && array_column($buttons,'text')===['💬 问题咨询','🤝 合作咨询'],'welcome has exactly two choices in one nonempty row');
    check(array_column($buttons,'callback_data')===['mtx:reply:question','mtx:reply:cooperation'] && !isset($card['keyboard']),'welcome uses message-attached callback buttons only');
    $callback=function(int $chat,string $field='question',array $extra=[])use(&$uid):array {
        $id=$uid++;return ['update_id'=>$id,'callback_query'=>array_replace_recursive(['id'=>'query-'.$id,'from'=>['id'=>$chat,'is_bot'=>false],'data'=>'mtx:reply:'.$field,'message'=>['message_id'=>654321,'date'=>time()-86400*7,'chat'=>['id'=>$chat,'type'=>'private'],'from'=>['id'=>123456,'is_bot'=>true],'text'=>'PRIVATE_CALLBACK_CARD_BODY']],$extra)];
    };
    foreach (['consult','install','support'] as $removed) check($deliver($callback(313130,$removed))===[],'removed category no longer triggers a template: '.$removed);
    check(problem(fn()=>$bot->receive($callback(313131),'bad'),403),'callback requires the same webhook secret');
    $beforeRoutes=$bot->store->read()['routes'];
    foreach (['question','cooperation'] as $field) {
        $u=$callback(313131,$field);$out=$deliver($u);
        check(array_column($out,0)===['answerCallbackQuery','sendMessage'] && $out[0][1]['callback_query_id']===$u['callback_query']['id'] && $out[1][1]['chat_id']===313131 && $out[1][1]['text']===$defaults[$field],'card click acknowledges spinner and replies privately: '.$field);
        check($deliver($u)===[],'duplicate callback update does not resend: '.$field);
    }
    check($bot->store->read()['routes']===$beforeRoutes && !isset($bot->store->read()['reply_receipts'][313131]),'menu callbacks create neither support routes nor received receipts');
    $out=$deliver($callback($admin,'cooperation'));check($out[1][1]['text']===$defaults['cooperation'] && ($out[1][1]['reply_markup']['remove_keyboard']??false),'operator can test a card and old input keyboard is removed');
    $edited=$defaults;$edited['question']='CUSTOM question <tag>';$bot->saveReplies($edited);
    $out=$deliver($callback(313131));check($out[1][1]['text']===$edited['question'] && !isset($out[1][1]['parse_mode']),'old card clicks immediately use saved plain-text templates');$bot->saveReplies($defaults);
    $invalid=[['data'=>'mtx:reply:unknown'],['data'=>'/block'],['data'=>[]],['id'=>''],['id'=>str_repeat('a',257)],['id'=>"query\ncontrol"],['from'=>['id'=>0]],['from'=>['is_bot'=>true]],['message'=>['chat'=>['type'=>'group']]],['message'=>['chat'=>['id'=>919191]]],['message'=>['from'=>['id'=>999999]]],['message'=>['from'=>['is_bot'=>false]]],['message'=>['message_id'=>0]],['inline_message_id'=>'foreign-inline']];
    foreach($invalid as $bad) check($deliver($callback(323232,'question',$bad))===[],'invalid or foreign callback ignored: '.json_encode($bad));
    $inaccessible=$callback(323232);unset($inaccessible['callback_query']['message']['from']);check($deliver($inaccessible)===[],'inaccessible card with no verifiable bot sender ignored');
    $bot->store->locked(function(&$s)use($bot){$s['blocked'][333334]=time();$bot->store->save($s);});
    check($deliver($callback(333334))===[],'blocked user cannot trigger card replies');
    for($i=0;$i<10;$i++)$deliver($callback(333335));check($deliver($callback(333335))===[],'card clicks share per-user rate limit');
    $failure=['answerCallbackQuery',new TelegramApiError(400)];$out=$deliver($callback(343434));
    check(count($out)===2 && $out[1][1]['text']===$defaults['question'],'expired callback acknowledgement does not block actual reply');
    $failure=['sendMessage',new TelegramApiError(429,false,1),343435];$u=$callback(343435);
    check(problem(fn()=>$bot->receive($u,$secret),503),'card reply 429 uses existing retry journal');
    $bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]['retry_at']=0;$bot->store->save($s);});
    $out=$deliver($u);check(count($out)===2 && $out[1][1]['chat_id']===343435 && $deliver($u)===[],'card reply retry sends content once');
    $failure=['sendMessage',new TelegramApiError(0,true),343436];$u=$callback(343436);$deliver($u);
    check($bot->store->read()['updates'][$u['update_id']]['status']==='uncertain' && $deliver($u)===[],'ambiguous card reply is never resent automatically');
    check(!str_contains(file_get_contents($bot->store->directory.'/state.json'),'PRIVATE_CALLBACK_CARD_BODY'),'callback card body is not stored');
    $failure=['deleteWebhook',new TelegramApiError(0,true)];try{$bot->disconnect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'pause is local-first even if remote removal is uncertain');
    check($deliver($message($b))===[],'paused valid webhook does not send');
    check($deliver($callback($b))===[] && problem(fn()=>$bot->refreshWebhook(),409),'paused bot neither answers cards nor refreshes subscriptions');
    $bot->saveSettings(['token'=>'123456:'.str_repeat('y',40),'admin_id'=>'55555']);$state=$bot->store->read();check(!$state['routes']&&!$state['updates']&&!$state['blocked']&&$state['settings']['secret']!==$secret,'new bot/operator clears prior identity mappings and rotates webhook secret');
    check(problem(fn()=>$bot->checkSecret($secret),403),'old webhook secret invalidated');
    $failure=['sendMessage',new TelegramApiError(403)];try{$bot->connect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'operator must start bot before activation');
    $badRegister=new TelegramApi(fn($method,$params)=>match($method){'getMe'=>['is_bot'=>true,'username'=>'MTXFixtureBot'],'sendMessage'=>['message_id'=>999],default=>false});
    try{(new TelegramBot(new App(),$badRegister))->connect();}catch(TelegramApiError){}check(!$bot->store->read()['settings']['enabled'],'unsuccessful registration never displays enabled');
    echo "\n$count Telegram checks passed; fake API only.\n";
} finally { if($oldConfig===false)putenv('MTX_CONFIG');else putenv('MTX_CONFIG='.$oldConfig);removeTree($dir); }
