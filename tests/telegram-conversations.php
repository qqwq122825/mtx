<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\{App,Store,TelegramStore,TelegramConversations,TelegramBot,TelegramApi,TelegramApiError,Problem};
$n=0;function check(bool $ok,string $label):void{global$n;if(!$ok)throw new RuntimeException('FAIL '.$label);$n++;echo 'PASS '.$label."\n";}
function remove(string $d):void{foreach(new FilesystemIterator($d)as$f){if($f->isDir()&&!$f->isLink())remove($f->getPathname());else unlink($f->getPathname());}rmdir($d);}
$d=sys_get_temp_dir().'/mtx-history-'.bin2hex(random_bytes(8));mkdir($d,0700);mkdir($d.'/objects');$old=getenv('MTX_CONFIG');
try{
 Store::write($d.'/state.json',json_encode(['schema'=>1,'next_game_id'=>2,'apps'=>['game-1'=>['game_id'=>1]],'releases'=>[]]));Store::write($d.'/config.php','<?php return '.var_export(['mount_path'=>'/r-history-fixture-123456789','base_url'=>'http://127.0.0.1:8787','local_http'=>true,'storage'=>$d],true).';');putenv('MTX_CONFIG='.$d.'/config.php');
 $calls=[];$failure=null;$id=100;$api=new TelegramApi(function($method,$params)use(&$calls,&$failure,&$id){$calls[]=[$method,$params];if($failure&&$failure[0]===$method){$e=$failure[1];$failure=null;throw$e;}return$method==='answerCallbackQuery'?true:['message_id'=>++$id];});
 $bot=new TelegramBot(new App(),$api);$bot->saveSettings(['token'=>'123456:'.str_repeat('x',40),'admin_id'=>'77777']);$bot->store->locked(function(&$s)use($bot){$s['settings']['enabled']=true;$bot->store->save($s);});$settings=$bot->store->read()['settings'];$history=$bot->conversations;
 $uid=1;$message=function($peer,$text,array $extra=[])use(&$uid){$i=$uid++;return['update_id'=>$i,'message'=>array_replace_recursive(['message_id'=>5000+$i,'date'=>time(),'chat'=>['type'=>'private','id'=>$peer],'from'=>['id'=>$peer,'is_bot'=>false,'first_name'=>'测试用户','username'=>'HistoryFixture'],'text'=>$text],$extra)];};
 $receive=fn($u)=>$bot->receive($u,$settings['secret']);
 check($history->listing($settings,[])['total']===0,'no old history invented');
 $receive($message(88888,'/start'));check($history->listing($settings,[])['total']===0,'welcome is not a human consultation');
 $u=$message(88888,"测试问题 <script>alert(1)</script>\n第二行");$receive($u);$list=$history->listing($settings,[]);$routes=$bot->store->read()['routes'];$copy=array_key_last($routes);
 check($list['total']===1&&$list['rows'][0]['username']==='HistoryFixture'&&$list['rows'][0]['status']==='waiting','incoming question records sender identity and pending reply status');
 check($history->detail($settings,['peer'=>'88888'])['messages'][0]['text']===$u['message']['text'],'detail retains plain multiline question for safe frontend rendering');
 $receive($u);check($history->detail($settings,['peer'=>'88888'])['total']===1,'duplicate webhook does not duplicate history');
 $reply=$message(77777,'我会尽快处理',['reply_to_message'=>['message_id'=>$copy,'text'=>'QUOTED_PRIVATE_NOT_STORED']]);$receive($reply);$detail=$history->detail($settings,['peer'=>'88888']);
 check($detail['total']===2&&$detail['messages'][1]['direction']==='out'&&$detail['messages'][1]['status']==='delivered','operator response text and successful delivery recorded');
 check($history->listing($settings,[])['rows'][0]['status']==='replied','confirmed operator response changes list to replied');
 $receive($message(88888,'新的问题',['from'=>['username'=>'NewHistoryHandle']]));check($history->listing($settings,[])['rows'][0]['status']==='waiting'&&$history->listing($settings,[])['rows'][0]['username']==='NewHistoryHandle','new question reopens waiting state and updates handle');
 $failure=['copyMessage',new TelegramApiError(403)];$receive($message(77777,'未送达的回复',['reply_to_message'=>['message_id'=>$copy]]));
 check($history->listing($settings,[])['rows'][0]['status']==='exception'&&array_slice($history->detail($settings,['peer'=>'88888'])['messages'],-1)[0]['status']==='failed','failed reply never appears as successfully sent');
 $failure=['sendMessage',new TelegramApiError(403)];$receive($message(77777,'送达但回执失败',['reply_to_message'=>['message_id'=>$copy]]));
 check(array_slice($history->detail($settings,['peer'=>'88888'])['messages'],-1)[0]['status']==='delivered','success receipt failure does not downgrade actual outgoing copy');
 $failure=['copyMessage',new TelegramApiError(0,true)];$u=$message(88889,'超时问题');$receive($u);$receive($u);
 check($history->detail($settings,['peer'=>'88889'])['total']===1&&$history->detail($settings,['peer'=>'88889'])['messages'][0]['status']==='uncertain','ambiguous sends remain uncertain and deduplicated');
 $failure=['copyMessage',new TelegramApiError(429,false,1)];$u=$message(88890,'限流问题');try{$receive($u);}catch(Problem$e){check($e->status===503,'rate limit requests webhook retry');}
 check($history->detail($settings,['peer'=>'88890'])['messages'][0]['status']==='retry','rate-limited copy shown as retry');
 $bot->store->locked(function(&$s)use($bot,$u){$s['updates'][$u['update_id']]['retry_at']=0;$bot->store->save($s);});$receive($u);check($history->detail($settings,['peer'=>'88890'])['total']===1&&$history->detail($settings,['peer'=>'88890'])['messages'][0]['status']==='delivered','retry updates same row without duplicate');
 $media=$message(88891,'');unset($media['message']['text']);$media['message']['document']=['file_id'=>'PRIVATE_FILE_ID','file_name'=>'截图.png'];$media['message']['caption']='图片说明';$receive($media);
 $m=$history->detail($settings,['peer'=>'88891'])['messages'][0];check($m['type']==='文件'&&$m['file_name']==='截图.png'&&$m['text']==='图片说明','media metadata and captions supported without downloading');
 $raw=file_get_contents($bot->store->directory.'/conversations.json');check(!str_contains($raw,$settings['token'])&&!str_contains($raw,$settings['secret'])&&!str_contains($raw,'PRIVATE_FILE_ID')&&!str_contains($raw,'QUOTED_PRIVATE_NOT_STORED'),'credential, file ID and nested quote objects are excluded');
 $stateRaw=file_get_contents($bot->store->directory.'/state.json');check(!str_contains($stateRaw,'新的问题')&&!str_contains($stateRaw,'HistoryHandle'),'history stays separate from credential and relay state');
 check($history->listing($settings,['q'=>'@NewHistoryHandle'])['total']===1&&$history->listing($settings,['q'=>'88891'])['total']===1,'search matches username and numeric ID');
 check($history->listing($settings,['status'=>'replied'])['counts']['all']===4,'filter retains global counters');
 $before=count($history->read($settings)['messages']);$receive($message(77777,'/help'));$bot->store->locked(function(&$s)use($bot){$s['blocked'][88892]=time();$bot->store->save($s);});$receive($message(88892,'blocked'));$receive($message(88893,'group',['chat'=>['type'=>'group']]));check(count($history->read($settings)['messages'])===$before,'commands, blocked senders and groups are excluded');
 $history->configure(['revision'=>'0','days'=>'30','enabled'=>'0']);$receive($message(88894,'PAUSED_HISTORY'));check($history->detail($settings,['peer'=>'88894'])['total']===0&&count($bot->store->read()['routes'])>0,'disabling history preserves normal relay');
 try{$history->configure(['revision'=>'0','days'=>'7']);check(false,'stale should fail');}catch(Problem$e){check($e->status===409,'stale privacy settings rejected');}
 $history->configure(['revision'=>'1','days'=>'7','enabled'=>'1']);
 $path=$bot->store->directory.'/conversations.json';$h=json_decode(file_get_contents($path),true);foreach($h['messages']as&$m)$m['at']=time()-8*86400;unset($m);Store::write($path,json_encode($h));
 check($history->listing($settings,[])['total']===0,'expired text is not returned even before scheduled cleanup');$history->cleanup();check(!json_decode(file_get_contents($path),true)['messages'],'scheduled cleanup removes expired text from disk');
 $store=$bot->store;$store->locked(function($s)use($history){for($i=0;$i<25;$i++){$key=$history->record($s['settings'],200+$i,90000+$i,'in',['message_id'=>$i+1,'from'=>['first_name'=>'分页测试'],'text'=>'Page '.$i]);$history->status($s['settings'],$key,'delivered');}});
 check(count($history->listing($settings,[])['rows'])===20&&count($history->listing($settings,['page'=>'2'])['rows'])===5,'server-side list pagination');
 $h=json_decode(file_get_contents($path),true);$base=array_values($h['messages'])[0];$h['messages']=[];for($i=0;$i<5000;$i++)$h['messages'][$i.':in']=array_replace($base,['id'=>$i.':in','update'=>$i,'peer'=>90000]);Store::write($path,json_encode($h));
 $store->locked(fn($s)=>$history->record($s['settings'],6000,90000,'in',['message_id'=>6001,'from'=>[],'text'=>'NEW_CAP_MESSAGE']));check(count($history->read($settings)['messages'])===5000&&!isset($history->read($settings)['messages']['0:in']),'history file bounded to latest 5000 messages');
 check(count($history->detail($settings,['peer'=>'90000'])['messages'])===50&&$history->detail($settings,['peer'=>'90000'])['total']===5000,'detail has independent 50-message pagination');
 $other=$settings;$other['admin_id']=99999;check($history->listing($other,[])['total']===0,'different bot/admin binding never exposes old history');
 check((fileperms($path)&0777)===0600,'history file private mode');
 echo "\n$n conversation checks passed; fake API only.\n";
}finally{$old===false?putenv('MTX_CONFIG'):putenv('MTX_CONFIG='.$old);remove($d);}
