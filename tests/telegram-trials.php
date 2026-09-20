<?php
/** No real cards or API calls; separate private fixtures only. */
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MTX\{App,Store,TelegramStore,TelegramBot,TelegramTrials,TelegramApi,TelegramApiError,Problem};
$count=0;
function check(bool $v,string $label): void { global $count;if (!$v) throw new RuntimeException('FAIL '.$label);$count++;echo 'PASS '.$label."\n"; }
function rejects(callable $fn,int $code): bool { try {$fn();}catch(Problem $e){return $e->status===$code;}return false; }
function clean(string $dir): void { foreach(new FilesystemIterator($dir)as$f){if($f->isDir()&&!$f->isLink())clean($f->getPathname());else unlink($f->getPathname());}rmdir($dir); }
$dir=sys_get_temp_dir().'/mtx-trials-'.bin2hex(random_bytes(8));mkdir($dir,0700);mkdir($dir.'/objects');$old=getenv('MTX_CONFIG');
try {
 Store::write($dir.'/state.json',json_encode(['schema'=>1,'next_game_id'=>2,'apps'=>['game-1'=>['game_id'=>1]],'releases'=>[]]));
 Store::write($dir.'/config.php','<?php return '.var_export(['mount_path'=>'/r-trials-fixture-0123456789','base_url'=>'http://127.0.0.1:8787','local_http'=>true,'storage'=>$dir],true).';');putenv('MTX_CONFIG='.$dir.'/config.php');
 $store=new TelegramStore($dir);$trials=new TelegramTrials($store);
 $rev=fn()=>['revision'=>(string)TelegramTrials::read($store->read())['revision']];
 $save=function(bool $enabled=false)use($trials,$rev){$trials->save($rev()+['title'=>TelegramTrials::TITLE,'enabled'=>$enabled?'1':'0']);};
 $import=fn(string $codes)=>$trials->import($rev()+['codes'=>$codes]);
 check(TelegramTrials::button($store->read())===null,'old state has no trial entry by default');
 check(rejects(fn()=>$save(true),422),'empty stock cannot be enabled');$save();
 $activity=TelegramTrials::read($store->read())['activity']['id'];check(strlen($activity)===16,'saved preset uses unpredictable generation ID');
 check(rejects(fn()=>$trials->save(['revision'=>'0','title'=>'Old tab']),409),'stale admin form rejected');
 check(rejects(fn()=>$trials->save($rev()+['title'=>"Injected\nTitle"]),422),'multiline activity label rejected');
 check(rejects(fn()=>$import("Fixture_Card_1\nBAD CODE"),422) && !TelegramTrials::read($store->read())['cards'],'invalid batch is atomic');
 check($import("Fixture_Card_1\nFixture_Card_1\nFixture_Card_2")==['added'=>2,'skipped'=>1],'import deduplicates within a batch');
 check(TelegramTrials::button($store->read())===null,'import does not enable or send anything');$save(true);
 check(TelegramTrials::button($store->read())['callback_data']==='mtx:trial:'.$activity,'enabled entry uses current generation');
 $now=strtotime('2026-09-12 15:59:59 UTC');
 check(TelegramTrials::day($now)==='2026-09-12' && TelegramTrials::day($now+1)==='2026-09-13','daily reset uses Shanghai midnight independently of server timezone');
 $claim=function(int $peer,int $id,int $at,?string $generation=null)use($store,$activity){return $store->locked(function(&$s)use($store,$peer,$id,$at,$generation,$activity){$s['updates'][$id]??=['at'=>$at,'status'=>'working','steps'=>[]];$r=TelegramTrials::reserve($s,$peer,$id,$generation??$activity,$at);$store->save($s);return $r;});};
 $one=$claim(10001,1,$now);$again=$claim(10001,2,$now);
 check($one['kind']==='card' && !$one['repeat'] && $again['repeat'] && $again['card']===$one['card'],'same user gets only the original card on repeat click');
 $tomorrow=$claim(10001,3,$now+1);check($tomorrow['kind']==='card' && $tomorrow['card']!==$one['card'],'same user can claim one new card after Shanghai midnight');
 check($claim(10001,1,$now+1)===$one,'same update retry across midnight keeps original allocation');
 $empty=$claim(20002,4,$now+1);check($empty['kind']==='empty' && !isset(TelegramTrials::read($store->read())['claims']['2026-09-13:20002']),'empty inventory never consumes daily eligibility');
 $import('Fixture_Card_3');check($claim(20002,4,$now+1)===$empty,'same retry keeps empty decision even after replenishment');
 check($claim(20002,5,$now+1)['kind']==='card','fresh click after replenishment succeeds');
 $trials->pause($rev());check(TelegramTrials::button($store->read())===null && $claim(30003,6,$now+1)['kind']==='closed','pause hides new entry and disables old buttons');
 $save(true);check($claim(30003,6,$now+1)['kind']==='closed','paused-click retry never starts allocating after reopen');
 check(rejects(fn()=>$trials->delete($rev()+['confirm_delete'=>'bad']),422),'delete requires typed confirmation');
 $trials->delete($rev()+['confirm_delete'=>'删除活动']);check(!TelegramTrials::read($store->read())['cards'] && !TelegramTrials::read($store->read())['activity'],'delete purges settings and plaintext cards');
 check(count(TelegramTrials::read($store->read())['used'])===3 && count(TelegramTrials::read($store->read())['claims'])===3,'delete preserves daily limits and allocated fingerprints');
 $save();$new=TelegramTrials::read($store->read())['activity']['id'];check($new!==$activity,'new activity has a new generation');
 check($import("Fixture_Card_1\nFixture_Card_4")==['added'=>1,'skipped'=>1],'allocated card cannot be reimported after deletion');$save(true);
 check($claim(40004,7,$now+1)['kind']==='closed','old activity buttons never claim from recreated inventory');
 check($claim(10001,8,$now+1,$new)['kind']==='claimed','recreating activity does not reset today limits');
 check(TelegramTrials::summary($store->read())['available']===1,'denied claims do not consume new stock');
 // Inventory removal must be atomic and must recheck allocation (claims do not bump revision).
 $hash4=hash('sha256','Fixture_Card_4');
 check(rejects(fn()=>$trials->deleteCards($rev()),422),'empty card selection rejected');
 check(rejects(fn()=>$trials->deleteCards($rev()+['cards'=>['bad']]),422),'malformed card selection rejected');
 check(rejects(fn()=>$trials->deleteCards(['revision'=>'0','card'=>$hash4]),409),'stale card deletion rejected');
 $import("Fixture_Card_5\nFixture_Card_6");$hash5=hash('sha256','Fixture_Card_5');$hash6=hash('sha256','Fixture_Card_6');
 $staleDelete=$rev()+['cards'=>[$hash4,$hash5]];
 $claim(50005,9,$now+1,$new);
 $beforeDelete=TelegramTrials::read($store->read());
 check(rejects(fn()=>$trials->deleteCards($staleDelete),409) && TelegramTrials::read($store->read())===$beforeDelete,'claim racing deletion protects allocated card and entire batch');
 check(rejects(fn()=>$trials->deleteCards($rev()+['cards'=>[$hash5,str_repeat('a',64)]]),409) && TelegramTrials::read($store->read())===$beforeDelete,'missing card rejects entire batch');
 check($trials->deleteCards($rev()+['card'=>$hash5,'cards'=>[$hash4]])===1,'single-card submit ignores unrelated checkbox selection');
 check($trials->deleteCards($rev()+['cards'=>[$hash6,$hash6]])===1,'bulk removal deduplicates selected hashes');
 $afterDelete=TelegramTrials::read($store->read());
 check($afterDelete['activity']===$beforeDelete['activity'] && $afterDelete['used']===$beforeDelete['used'] && $afterDelete['claims']===$beforeDelete['claims'] && isset($afterDelete['cards'][$hash4]),'inventory deletion preserves activity, assigned cards and claim history');
 check($claim(50005,10,$now+1,$new)['repeat'],'repeat claim still works after inventory removal');
 // Clear only this synthetic fixture for current-time relay tests.
 $store->locked(function(&$s)use($store){$s=TelegramStore::emptyState();$store->save($s);});$save();$import(implode("\n",array_map(fn($i)=>'RELAY_FIXTURE_CARD_'.$i,range(1,30))));$save(true);
 $calls=[];$failure=null;$mid=200;
 $api=new TelegramApi(function($method,$p)use(&$calls,&$failure,&$mid){$calls[]=[$method,$p];if($failure && $method==='sendMessage' && isset($p['text']) && str_contains($p['text'],'RELAY_FIXTURE_CARD_')){$e=$failure;$failure=null;throw$e;}return $method==='answerCallbackQuery'?true:['message_id'=>++$mid];});
 $bot=new TelegramBot(new App(),$api);$bot->saveSettings(['token'=>'123456:'.str_repeat('x',40),'admin_id'=>'77777']);
 check(count(TelegramTrials::read($store->read())['cards'])===30 && !TelegramTrials::read($store->read())['activity']['enabled'],'initial bot binding preserves stock but leaves activity paused');$save(true);
 $store->locked(function(&$s)use($store){$s['settings']['enabled']=true;$store->save($s);});$secret=$store->read()['settings']['secret'];$generation=TelegramTrials::read($store->read())['activity']['id'];$uid=100;
 $click=function(int $peer,array $extra=[])use(&$uid,$generation){$id=$uid++;return ['update_id'=>$id,'callback_query'=>array_replace_recursive(['id'=>'query-'.$id,'data'=>'mtx:trial:'.$generation,'from'=>['id'=>$peer,'is_bot'=>false],'message'=>['message_id'=>11,'from'=>['id'=>123456,'is_bot'=>true],'chat'=>['type'=>'private','id'=>$peer]]],$extra)];};
 $deliver=function(array $u)use($bot,$secret,&$calls){$n=count($calls);$bot->receive($u,$secret);return array_slice($calls,$n);};
 $u=['update_id'=>$uid++,'message'=>['message_id'=>12,'date'=>time(),'from'=>['id'=>30001,'is_bot'=>false],'chat'=>['id'=>30001,'type'=>'private'],'text'=>'/start']];$out=$deliver($u);
 check(count($out[0][1]['reply_markup']['inline_keyboard'])===2 && $out[0][1]['reply_markup']['inline_keyboard'][1][0]['callback_data']==='mtx:trial:'.$generation,'welcome retains inquiry row plus activity button');
 $u=$click(30001);$out=$deliver($u);$body=$out[1][1]['text'];
 check(array_column($out,0)===['answerCallbackQuery','sendMessage'] && $out[1][1]['chat_id']===30001 && str_contains($body,'RELAY_FIXTURE_CARD_1'),'card only sent to actual click owner, not operator');
 check(TelegramTrials::summary($store->read())===['available'=>29,'allocated'=>1,'attention'=>0],'confirmed card updates inventory delivery state');
 check($deliver($u)===[],'duplicate webhook does not send again');
 $out=$deliver($click(30001));check(str_contains($out[1][1]['text'],'RELAY_FIXTURE_CARD_1') && str_contains($out[1][1]['text'],'同一张卡') && TelegramTrials::summary($store->read())['allocated']===1,'fresh same-day click redisplays original without consuming a card');
 $out=$deliver($click(30002));check(str_contains($out[1][1]['text'],'RELAY_FIXTURE_CARD_2'),'another user gets a different card');
 foreach ([['message'=>['chat'=>['type'=>'group']]],['message'=>['chat'=>['id'=>99999]]],['message'=>['from'=>['id'=>987654]]],['from'=>['is_bot'=>true]],['inline_message_id'=>'foreign'],['data'=>'mtx:trial:../x']] as $bad) check($deliver($click(30003,$bad))===[],'invalid callback cannot allocate or send');
 $store->locked(function(&$s)use($store){$s['blocked'][30004]=time();$store->save($s);});check($deliver($click(30004))===[],'blocked user cannot claim');
 $u=$click(30005);check(rejects(fn()=>$bot->receive($u,'bad'),403),'forged webhook secret cannot claim');
 $failure=new TelegramApiError(429,false,1);$u=$click(30006);check(rejects(fn()=>$bot->receive($u,$secret),503),'definite rate limit uses existing webhook retry');$allocated=TelegramTrials::summary($store->read())['allocated'];
 $store->locked(function(&$s)use($store,$u){$s['updates'][$u['update_id']]['retry_at']=0;$store->save($s);});$out=$deliver($u);
 check(TelegramTrials::summary($store->read())['allocated']===$allocated && str_contains($out[1][1]['text'],'RELAY_FIXTURE_CARD_3'),'429 retry keeps exactly the reserved card');
 $failure=new TelegramApiError(0,true);$u=$click(30007);$deliver($u);check(TelegramTrials::summary($store->read())['attention']===1 && $deliver($u)===[],'ambiguous delivery holds card and never automatically repeats');
 $out=$deliver($click(30007));check(str_contains($out[1][1]['text'],'RELAY_FIXTURE_CARD_4') && TelegramTrials::summary($store->read())['attention']===0,'explicit new click retrieves same ambiguous card and confirms delivery');
 $failure=new TelegramApiError(403);$deliver($click(30008));$allocated=TelegramTrials::summary($store->read())['allocated'];
 $out=$deliver($click(30008));check(str_contains($out[1][1]['text'],'RELAY_FIXTURE_CARD_5') && TelegramTrials::summary($store->read())['allocated']===$allocated,'failed send keeps card owned by same user');
 $trials->pause($rev());$out=$deliver($click(30009));check(str_contains($out[1][1]['text'],'暂停或结束') && TelegramTrials::summary($store->read())['allocated']===$allocated,'disabled old card replies with status and allocates nothing');$save(true);
 // Simulated interrupted send has persisted reservation but no API result.
 $u=$click(30010);$store->locked(function(&$s)use($store,$u,$generation){$id=$u['update_id'];$s['updates'][$id]=['at'=>time(),'status'=>'working','steps'=>[]];TelegramTrials::reserve($s,30010,$id,$generation,time());$s['updates'][$id]['steps']['trial_card']=['status'=>'pending'];$store->save($s);});$out=$deliver($u);
 check(!array_filter($out,fn($c)=>str_contains($c[1]['text']??'','RELAY_FIXTURE_CARD_')) && $store->read()['updates'][$u['update_id']]['status']==='uncertain','process interruption never blindly sends a second card');
 if (function_exists('pcntl_fork')) {
  $children=[];foreach([41001,41001,41002,41003] as $i=>$peer){$pid=pcntl_fork();if($pid===0){$store->locked(function(&$s)use($store,$peer,$i,$generation){$id=800+$i;$s['updates'][$id]=['at'=>time(),'status'=>'working','steps'=>[]];TelegramTrials::reserve($s,$peer,$id,$generation,time());$store->save($s);});exit(0);} $children[]=$pid;}
  foreach($children as$pid){pcntl_waitpid($pid,$status);check(pcntl_wexitstatus($status)===0,'concurrent claimant worker completed');}
  $state=$store->read();$hashes=array_map(fn($i)=>$state['updates'][$i]['trial']['card'],range(800,803));check($hashes[0]===$hashes[1] && count(array_unique($hashes))===3,'concurrent same user shares one card; different users receive unique stock');
 }
 $state=$store->read();check(!str_contains(json_encode($state['updates']),'RELAY_FIXTURE_CARD_') && !str_contains(json_encode($state['events']),'RELAY_FIXTURE_CARD_'),'relay journal and event logs contain no card plaintext');
 check((fileperms($store->directory.'/state.json')&0777)===0600 && (fileperms($store->directory)&0777)===0700,'card inventory is private on disk');
 $before=TelegramTrials::summary($state);$store->locked(function(&$s)use($store){$s['settings']['enabled']=false;$store->save($s);});$bot->saveSettings(['token'=>'654321:'.str_repeat('y',40),'admin_id'=>'77777']);
 check(TelegramTrials::summary($store->read())===$before && !TelegramTrials::read($store->read())['activity']['enabled'],'changing bot binding preserves cards and daily limits but pauses giveaway');
 echo "\n$count trial checks passed; synthetic cards and fake API only.\n";
} finally { $old===false?putenv('MTX_CONFIG'):putenv('MTX_CONFIG='.$old);clean($dir); }
