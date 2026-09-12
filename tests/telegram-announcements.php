<?php
/** Isolated file state + fake Telegram transport and clock. Never sends real messages. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\{App,Store,TelegramApi,TelegramApiError,TelegramBot,TelegramAnnouncements,Problem};
$n=0;function check(bool $v,string $label):void {global $n;if(!$v)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label."\n";}
function problem(callable $f,int $code):bool {try{$f();}catch(Problem $e){return $e->status===$code;}return false;}
function clean(string $d):void {foreach(new FilesystemIterator($d) as $f){if($f->isDir())clean($f->getPathname());else unlink($f->getPathname());}rmdir($d);}
$dir=sys_get_temp_dir().'/mtx-announcement-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/objects');$old=getenv('MTX_CONFIG');
try {
    Store::write($dir.'/state.json',json_encode(['schema'=>1,'next_game_id'=>2,'apps'=>['game'=>['game_id'=>1]],'releases'=>[]]));
    Store::write($dir.'/config.php','<?php return '.var_export(['storage'=>$dir,'mount_path'=>'/r-announcement-test-123456','base_url'=>'http://127.0.0.1','local_http'=>true],true).';');putenv('MTX_CONFIG='.$dir.'/config.php');
    $app=new App();$now=1800000000;$calls=[];$fail=null;$resultOverride=null;$id=100;
    $api=new TelegramApi(function($m,$p)use(&$calls,&$fail,&$resultOverride,&$id,$dir){
        $calls[]=[$m,$p];file_put_contents($dir.'/calls.log',$m."\n",FILE_APPEND|LOCK_EX);
        if ($fail && $fail[0]===$m) {$e=$fail[1];$fail=null;throw $e;}
        if ($resultOverride && $resultOverride[0]===$m) {$v=$resultOverride[1];$resultOverride=null;return $v;}
        return match($m){'getChat'=>['id'=>-10012345678,'type'=>'channel'],'deleteMessage'=>true,default=>['message_id'=>++$id,'text'=>'NOT_RETAINED_RESPONSE']};
    });
    $ann=new TelegramAnnouncements($app,$api,function()use(&$now){return $now;});$bot=new TelegramBot($app,$api);
    $input=['text'=>'🔴 **防骗郑重声明**'."\n".'正文 & <script>alert(1)</script> *注意*','contact'=>'@MySupport','bot_contact'=>'@MyBot_name','button1_text'=>'官方自助卡网','button1_url'=>'https://store.example.com','button2_text'=>'官方频道','button2_url'=>'https://t.me/MyChannel','target'=>'@MyChannel','schedule_enabled'=>'0','delete_enabled'=>'0','schedule_mode'=>'interval','interval_minutes'=>'5','delete_minutes'=>'2','first_at'=>''];
    $save=function(array $overrides=[])use($ann,$input){$ann->save(array_replace($input,$overrides));};
    $reset=function()use($ann,$bot){$s=MTX\TelegramStore::emptyState();$s['settings']=['token'=>'123456:'.str_repeat('x',40),'admin_id'=>77777,'enabled'=>true,'secret'=>str_repeat('a',64),'username'=>'MyBot_name'];$ann->store->save($s);};
    check($ann->read()['card']['schedule_enabled']===false && $ann->read()['card']['delete_enabled']===false,'both timers default off');
    check(!str_contains(json_encode($ann->read()),'iosAs'),'third-party handles are not configured');
    $save();check(count($calls)===0 && $ann->read()['revision']===1,'saving a draft needs no token and makes no API calls');
    check(problem(fn()=>$save(['schedule_enabled'=>'1']),409),'schedule requires enabled bot');
    check(problem(fn()=>$ann->sendNow(str_repeat('a',32)),409),'manual send requires enabled bot');
    foreach(['12345','0','-01','@ab','https://t.me/MyChannel',[],str_repeat('9',40)] as $bad)check(problem(fn()=>$save(['target'=>$bad]),422),'invalid target rejected '.json_encode($bad));
    foreach(['javascript:alert(1)','http://example.com','https://u:p@example.com',"https://example.com/\nfoo"] as $bad)check(problem(fn()=>$save(['button1_url'=>$bad]),422),'invalid button link rejected');
    check(problem(fn()=>$save(['button1_text'=>'']),422),'link button needs a label');
    check(problem(fn()=>$save(['delete_minutes'=>'2880']),422),'deletion bounded below Telegram 48-hour cutoff');
    check(problem(fn()=>$save(['interval_minutes'=>'4']),422),'repeat interval minimum five minutes');
    check(problem(fn()=>$save(['text'=>str_repeat('😀',2100)]),422),'Telegram UTF-16 size cap including emoji');
    check(problem(fn()=>$save(['contact'=>'x" onclick="alert(1)']),422),'handle syntax validated');
    check(problem(fn()=>$save(['schedule_enabled'=>['1']]),422),'malformed switch rejected');
    $html=TelegramAnnouncements::html($ann->read()['card']);check(str_contains($html,'<b>防骗郑重声明</b>') && str_contains($html,'<i>注意</i>') && str_contains($html,'&lt;script&gt;') && !str_contains($html,'<script>'),'preview and outgoing HTML escape text, permit only emphasis and generated contacts');
    $reset();$save(['delete_enabled'=>'1']);$nonce=str_repeat('b',32);$ann->sendNow($nonce);
    check(array_column($calls,0)===['getChat','sendMessage'],'public target resolved before sending');
    $p=$calls[1][1];check($p['chat_id']===-10012345678 && $p['parse_mode']==='HTML' && $p['link_preview_options']['is_disabled'] && count($p['reply_markup']['inline_keyboard'][0])===2,'native formatted card and inline URL buttons');
    $jobs=$ann->read()['jobs'];$j=end($jobs);check($j['status']==='sent' && $j['delete_at']===$now+120 && $j['chat_id']===-10012345678,'successful send atomically records canonical target and deletion');
    $ann->sendNow($nonce);check(count($calls)===2,'duplicate manual request nonce does not resend');
    check(!str_contains(file_get_contents($ann->store->directory.'/state.json'),'NOT_RETAINED_RESPONSE'),'API message body not retained');
    $now+=119;$ann->tick();check(count($calls)===2,'delete not early');$now++;$ann->tick();check(end($calls)===['deleteMessage',['chat_id'=>-10012345678,'message_id'=>$j['message_id']]],'due deletion uses exact bot-sent message and stable target');
    $before=count($calls);$ann->tick();check(count($calls)===$before && $ann->read()['jobs']['manual-'.$nonce]['delete_status']==='deleted','successful deletion not repeated');
    $ann->sendNow(str_repeat('c',32),true);check(end($calls)[1]['chat_id']===77777,'test goes only to saved personal admin');
    $save(['delete_enabled'=>'0','target'=>'@NextChannel']);$now+=120;$ann->pause();$ann->tick();check(end($calls)[0]==='deleteMessage' && end($calls)[1]['chat_id']===77777,'editing/pausing preserves prior deletion with original target');
    $reset();$save(['button1_url'=>'','button2_url'=>'']);$ann->sendNow(str_repeat('d',32));check(!isset(end($calls)[1]['reply_markup']),'blank URLs omit buttons');
    $reset();$save();$resultOverride=['getChat',['id'=>88888,'type'=>'private']];$before=count($calls);check($ann->sendNow(str_repeat('e',32))==='failed' && count($calls)===$before+1,'username resolving to a private person never receives announcement');
    $reset();$save(['schedule_enabled'=>'1','delete_enabled'=>'1']);$first=$ann->read()['next_at'];check($first===$now+300,'interval starts after save, not immediately');
    $ann->tick();$before=count($calls);$now=$first;$ann->tick();check(count($calls)===$before+2 && $ann->read()['next_at']===$now+300,'due scheduled send advances deadline');
    $before=count($calls);$ann->tick();check(count($calls)===$before,'same minute tick no duplicate');
    $now+=1800;$before=count($calls);$ann->tick();check(count(array_filter(array_slice($calls,$before),fn($c)=>$c[0]==='sendMessage'))===1,'outage skips missed intervals instead of catch-up burst');
    $reset();$save(['schedule_enabled'=>'1','schedule_mode'=>'once']);$now+=300;$ann->tick();check(!$ann->read()['card']['schedule_enabled'] && $ann->read()['next_at']===0,'one-time schedule stops after due send');
    $future=(new DateTimeImmutable('@'.($now+600)))->setTimezone(new DateTimeZone('Asia/Shanghai'))->format('Y-m-d\TH:i');$save(['schedule_enabled'=>'1','first_at'=>$future]);check($ann->read()['next_at']===intdiv($now+600,60)*60,'first send parsed in Beijing timezone');
    check(problem(fn()=>$save(['schedule_enabled'=>'1','first_at'=>'2026-02-30T12:00']),422),'invalid calendar date rejected');
    check(problem(fn()=>$save(['schedule_enabled'=>'1','first_at'=>'2020-01-01T12:00']),422),'past first send rejected');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['sendMessage',new TelegramApiError(429,false,90)];check($ann->sendNow(str_repeat('f',32))==='retry','known rate-limit queues resumable job');$before=count($calls);$ann->tick();check(count($calls)===$before,'respects retry-after');$now+=90;$ann->tick();check($ann->read()['jobs']['manual-'.str_repeat('f',32)]['status']==='sent','rate-limit eventually resumes once');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['sendMessage',new TelegramApiError(0,true)];check($ann->sendNow(str_repeat('1',32))==='uncertain' && !$ann->read()['card']['schedule_enabled'],'ambiguous send pauses schedule and is not retried');$before=count($calls);$now+=600;$ann->tick();check(count($calls)===$before,'timeout not blindly resent');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['getChat',new TelegramApiError(0,true)];check($ann->sendNow(str_repeat('2',32))==='retry','read-only resolution timeout can retry');$now+=60;$ann->tick();check($ann->read()['jobs']['manual-'.str_repeat('2',32)]['status']==='sent','resolution retry sends once');
    $reset();$save(['delete_enabled'=>'1']);$ann->sendNow(str_repeat('3',32));$fail=['deleteMessage',new TelegramApiError(429,false,75)];$now+=120;$ann->tick();check($ann->read()['jobs']['manual-'.str_repeat('3',32)]['delete_status']==='retry','delete rate-limit retried later');$now+=75;$ann->tick();check($ann->read()['jobs']['manual-'.str_repeat('3',32)]['delete_status']==='deleted','delete retry completes');
    $reset();$save(['delete_enabled'=>'1']);$ann->sendNow(str_repeat('4',32));$fail=['deleteMessage',new TelegramApiError(403)];$now+=120;$ann->tick();check($ann->read()['jobs']['manual-'.str_repeat('4',32)]['delete_status']==='failed','missing delete permission retained as visible failure');
    $reset();$save(['delete_enabled'=>'1']);$ann->sendNow(str_repeat('5',32));$now+=48*3600;$before=count($calls);$ann->tick();check(count($calls)===$before && $ann->read()['jobs']['manual-'.str_repeat('5',32)]['delete_status']==='expired','past cutoff marked expired without futile API call');
    $reset();$save(['delete_enabled'=>'1']);$ann->sendNow(str_repeat('6',32));$ann->store->locked(function(&$s)use($ann){$s['settings']['enabled']=false;$ann->store->save($s);});
    check(problem(fn()=>$bot->saveSettings(['token'=>'','admin_id'=>'99999']),409),'binding change blocked while prior bot deletion pending');
    $now+=120;$ann->tick();check(end($calls)[0]==='deleteMessage','pausing support still honors pending deletions');
    $bot->saveSettings(['token'=>'','admin_id'=>'99999']);check($ann->read()['revision']===0 && !$ann->read()['jobs'],'binding change resets announcement settings and old routes');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['sendMessage',new TelegramApiError(429,false,60)];$ann->sendNow(str_repeat('7',32));
    $ann->store->locked(function(&$s)use($ann){$s['announcements']['jobs']['manual-'.str_repeat('7',32)]['status']='pending';$ann->store->save($s);});$before=count($calls);$ann->tick();check(count($calls)===$before && $ann->read()['jobs']['manual-'.str_repeat('7',32)]['status']==='uncertain','crash-pending send recovered without retransmission');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['sendMessage',new TelegramApiError(429,false,60)];$ann->sendNow(str_repeat('8',32));$ann->pause();$now+=600;$before=count($calls);$ann->tick();check(count($calls)===$before && $ann->read()['jobs']['manual-'.str_repeat('8',32)]['status']==='cancelled','pause cancels unsent retries and schedule');
    $reset();$save(['schedule_enabled'=>'1']);$fail=['sendMessage',new TelegramApiError(403)];$ann->sendNow(str_repeat('9',32),true);check($ann->read()['card']['schedule_enabled'],'failed admin test does not disable channel schedule');
    $reset();$save();
    if (function_exists('pcntl_fork')) {
        file_put_contents($dir.'/calls.log','');$children=[];
        for($i=0;$i<2;$i++){ $pid=pcntl_fork();if($pid===0){$ann->sendNow(str_repeat('a',32));exit(0);} $children[]=$pid; }
        foreach($children as $pid){pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'parallel worker completed');}
        check(substr_count(file_get_contents($dir.'/calls.log'),'sendMessage')===1,'file lock deduplicates parallel manual sends');
    }
    check((fileperms($ann->store->directory.'/state.json')&0777)===0600,'announcement state stays private');
    echo "\n$n announcement checks passed. No external calls.\n";
} finally {putenv($old===false?'MTX_CONFIG':'MTX_CONFIG='.$old);clean($dir);}
