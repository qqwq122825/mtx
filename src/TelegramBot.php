<?php
declare(strict_types=1);
namespace MTX;
final class TelegramBot
{
    public readonly TelegramStore $store;
    public function __construct(private readonly App $app,private readonly TelegramApi $api=new TelegramApi()) { $this->store=new TelegramStore($app->storage); }
    public static function id(mixed $id): int
    {
        if ((!is_int($id) && !is_string($id)) || !preg_match('/\A[1-9][0-9]{0,15}\z/',(string)$id) || (int)$id>4503599627370495) throw new Problem(422,'Telegram ID 应为正整数数字 ID。');
        return (int)$id;
    }
    /** Only the exact one-time command, authored privately by its owner, is considered. */
    public static function findPairingID(array $updates,string $command): ?int
    {
        $ids=[];
        foreach ($updates as $u) {
            $m=$u['message']??[];$id=$m['chat']['id']??null;
            if (($m['text']??'')!==$command || ($m['chat']['type']??'')!=='private' || ($m['from']['is_bot']??true)!==false || !is_int($id) || ($m['from']['id']??null)!==$id || isset($m['forward_origin']) || ($m['date']??0)<time()-600) continue;
            try { $ids[self::id($id)]=true; } catch (Problem) {}
        }
        return count($ids)===1?array_key_first($ids):null;
    }
    public function webhookURL(): string { return $this->app->url('/api/telegram-webhook.php'); }
    public function saveSettings(#[\SensitiveParameter] array $input): void
    {
        $this->store->locked(function (&$s) use ($input) {
            if ($s['settings']['enabled']) throw new Problem(409,'请先暂停机器人，再修改绑定。');
            $token=trim(Http::text($input,'token',150));
            if ($token==='') $token=$s['settings']['token'];
            if (!preg_match('/\A[1-9][0-9]{4,19}:[A-Za-z0-9_-]{30,100}\z/',$token)) throw new Problem(422,'请填写 BotFather 提供的完整 Bot Token。');
            $admin=self::id($input['admin_id']??null);
            $changed=$token!==$s['settings']['token'] || $admin!==$s['settings']['admin_id'];
            if ($changed) {
                foreach ($s['announcements']['jobs']??[] as $job) {
                    if (in_array($job['delete_status'],['waiting','pending','retry'],true)) throw new Problem(409,'还有待删除的公告，请完成清理后再更换机器人或管理员。');
                }
                $draft=$s['settings']['token']==='' ? ($s['announcements']??null) : null;
                $replies=$s['settings']['token']==='' ? ($s['auto_replies']??null) : null;
                $s=TelegramStore::emptyState();
                // A draft prepared before initial BotFather setup should survive that first binding.
                if ($draft) {$s['announcements']=TelegramAnnouncements::defaults();$s['announcements']['card']=$draft['card'];$s['announcements']['card']['schedule_enabled']=false;$s['announcements']['revision']=$draft['revision'];}
                if ($replies) $s['auto_replies']=$replies;
            }
            $s['settings']=['token'=>$token,'admin_id'=>$admin,'enabled'=>false,'secret'=>$s['settings']['secret']?:bin2hex(random_bytes(32)),'username'=>$s['settings']['username']];
            $this->store->save($s);
        });
    }
    public function saveReplies(array $input): void
    {
        $values=TelegramReplies::validate($input);
        $this->store->locked(function (&$s) use ($values) {
            $s['auto_replies']=$values;$this->store->save($s);
        });
    }
    public function connect(): void
    {
        if ($this->app->config['local_http'] || parse_url($this->webhookURL(),PHP_URL_SCHEME)!=='https') throw new Problem(422,'本地可保存配置；请部署到公网 HTTPS 后启用 Webhook。');
        $this->store->locked(function (&$s) {
            $c=$s['settings'];if (!$c['token'] || !$c['admin_id']) throw new Problem(422,'请先保存 Token 和管理员数字 ID。');
            $me=$this->api->call($c['token'],'getMe');
            if (!is_array($me) || ($me['is_bot']??false)!==true || !preg_match('/\A[A-Za-z0-9_]{5,64}\z/',$me['username']??'')) throw new TelegramApiError(0,true);
            // The admin must have started a private chat; no arbitrary recipient/broadcast UI.
            $this->api->call($c['token'],'sendMessage',['chat_id'=>$c['admin_id'],'text'=>'满天星客服机器人正在接入。收到用户消息后，请使用 Telegram 的“回复”功能回信。']);
            $s['settings']['username']=$me['username'];$s['settings']['enabled']=true;$this->store->save($s);
            try {
                $this->registerWebhook($c);
            } catch (TelegramApiError $e) { $s['settings']['enabled']=false;$this->store->save($s);throw $e; }
        });
    }
    private function registerWebhook(#[\SensitiveParameter] array $c): void
    {
        if ($this->api->call($c['token'],'setWebhook',['url'=>$this->webhookURL(),'secret_token'=>$c['secret'],'allowed_updates'=>['message','callback_query'],'max_connections'=>1,'drop_pending_updates'=>false])!==true) throw new TelegramApiError(0,true);
    }
    /** Update subscriptions in place; keep the existing binding, queue and conversations. */
    public function refreshWebhook(): void
    {
        if ($this->app->config['local_http'] || parse_url($this->webhookURL(),PHP_URL_SCHEME)!=='https') throw new Problem(422,'请在正式 HTTPS 后台更新消息订阅。');
        $this->store->locked(function ($s) {
            if (!$s['settings']['enabled']) throw new Problem(409,'请先启用机器人。');
            $this->registerWebhook($s['settings']);
        });
    }
    public function disconnect(): void
    {
        $this->store->locked(function (&$s) {
            $s['settings']['enabled']=false;$this->store->save($s);
            if ($s['settings']['token']) $this->api->call($s['settings']['token'],'deleteWebhook',['drop_pending_updates'=>false]);
        });
    }
    public function status(): array
    {
        return $this->store->locked(function ($s) {
            if (!$s['settings']['token']) throw new Problem(422,'请先保存机器人配置。');
            $status=$this->api->call($s['settings']['token'],'getWebhookInfo');
            if (!is_array($status)) throw new TelegramApiError(0,true);
            // Do not echo arbitrary URLs/error descriptions from the remote API.
            return ['matches'=>($status['url']??'')===$this->webhookURL(),'pending'=>max(0,(int)($status['pending_update_count']??0)),'has_error'=>isset($status['last_error_date']),'callbacks'=>in_array('callback_query',$status['allowed_updates']??[],true)];
        });
    }
    private function prune(array &$s): void
    {
        $now=time();
        $s['routes']=array_slice(array_filter($s['routes'],fn($v)=>$v['at']>$now-30*86400),-10000,null,true);
        $s['updates']=array_filter($s['updates'],fn($v)=>$v['at']>$now-3*86400);
        $s['rates']=array_filter($s['rates'],fn($v)=>$v['at']>$now-60);
        $s['events']=array_slice($s['events'],-100);
        $s['reply_receipts']=array_filter($s['reply_receipts']??[],fn($slot)=>is_array($slot) && is_int($slot['at']??null) && $slot['at']>$now-TelegramReplies::COOLDOWN);
    }
    private function event(array &$s,int $update,string $status,int $peer,int $code=0): void
    { $s['events'][]=['at'=>time(),'update_id'=>$update,'status'=>$status,'peer'=>$peer,'code'=>$code];$s['events']=array_slice($s['events'],-100); }
    /** Check the header before reading the body. Rechecked inside receive's lock. */
    public function checkSecret(string $secret): void
    {
        $c=$this->store->read()['settings'];
        if (!$c['secret'] || !hash_equals($c['secret'],$secret)) throw new Problem(403,'Webhook 校验失败。');
    }
    private function route(array $s,array $message): ?array
    {
        $reply=$message['reply_to_message']['message_id']??null;
        return is_int($reply)?($s['routes'][$reply]??null):null;
    }
    private function supported(array $m): bool
    {
        foreach (['text','photo','document','audio','voice','video','video_note','sticker','animation'] as $field) if (isset($m[$field])) return true;
        return false;
    }
    /** A card click may only answer its human owner in this bot's own private chat. */
    private function cardClick(mixed $query,#[\SensitiveParameter] array $settings): ?array
    {
        if (!is_array($query) || !is_string($query['id']??null) || !preg_match('/\A[\x21-\x7e]{1,256}\z/D',$query['id'])) return null;
        $from=$query['from']??[];$message=$query['message']??[];$data=$query['data']??null;
        if (!is_string($data) || !preg_match('/\Amtx:reply:(question|cooperation)\z/D',$data,$match)) return null;
        if (($from['is_bot']??true)!==false || !is_int($from['id']??null) || $from['id']<=0 || ($message['chat']['type']??'')!=='private' || ($message['chat']['id']??null)!==$from['id']) return null;
        if (($message['from']['is_bot']??false)!==true || ($message['from']['id']??null)!==(int)explode(':',$settings['token'],2)[0] || !is_int($message['message_id']??null) || $message['message_id']<1 || isset($query['inline_message_id'])) return null;
        return ['id'=>$query['id'],'peer'=>$from['id'],'field'=>$match[1]];
    }
    /** Store intent before each non-idempotent API call; never blindly resend an ambiguous call. */
    private function step(array &$s,int $update,string $step,string $method,array $params,?int $routePeer=null): array|bool
    {
        $job=&$s['updates'][$update];
        if (isset($job['steps'][$step])) {
            $done=$job['steps'][$step];
            if ($done['status']==='pending') throw new TelegramApiError(0,true);
            return $done['result'];
        }
        $job['steps'][$step]=['status'=>'pending'];$this->store->save($s);
        try { $result=$this->api->call($s['settings']['token'],$method,$params); }
        catch (TelegramApiError $e) {
            if (!$e->uncertain) unset($job['steps'][$step]);
            throw $e;
        }
        if (!is_array($result) || !is_int($result['message_id']??null) || $result['message_id']<1) throw new TelegramApiError(0,true);
        // Store only the result ID, never the response text/user/media metadata.
        $result=['message_id'=>$result['message_id']];
        $job['steps'][$step]=['status'=>'done','result'=>$result];
        if ($routePeer!==null) { $s['routes'][$result['message_id']]=['chat_id'=>$routePeer,'at'=>time()];$s['routes']=array_slice($s['routes'],-10000,null,true); }
        $this->store->save($s);return $result;
    }
    public function receive(#[\SensitiveParameter] array $update,string $secret): void
    {
        if (!is_int($update['update_id']??null) || $update['update_id']<0) throw new Problem(422,'Telegram update_id 格式异常。');
        $this->store->locked(function (&$s) use ($update,$secret) {
            $c=$s['settings'];
            if (!$c['secret'] || !hash_equals($c['secret'],$secret)) throw new Problem(403,'Webhook 校验失败。');
            if (!$c['enabled']) return;
            $this->prune($s);$id=$update['update_id'];$m=$update['message']??null;
            if (isset($s['updates'][$id]) && in_array($s['updates'][$id]['status'],['done','failed','uncertain'],true)) return;
            if (($s['updates'][$id]['retry_at']??0)>time()) throw new Problem(503,'机器人稍后重试。');
            $click=null;
            if (array_key_exists('callback_query',$update)) {
                $click=$this->cardClick($update['callback_query'],$c);if (!$click) return;
                $peer=$click['peer'];$text='';
            } else {
                if (!is_array($m) || ($m['chat']['type']??'')!=='private' || ($m['from']['is_bot']??true)!==false || !is_int($m['chat']['id']??null) || $m['chat']['id']<=0 || ($m['from']['id']??null)!==$m['chat']['id'] || !is_int($m['message_id']??null) || $m['message_id']<1 || !is_int($m['date']??null) || $m['date']<time()-86400 || $m['date']>time()+60) return;
                $peer=$m['chat']['id'];$text=is_string($m['text']??null)?trim($m['text']):'';
            }
            $admin=$peer===$c['admin_id'];
            if (!isset($s['updates'][$id])) {
                // Bounds prevent disk growth; no eviction of recent deduplication IDs.
                if (count($s['updates'])>=20000 || count($s['rates'])>=10000) throw new Problem(503,'机器人繁忙，请稍后重试。');
                $s['updates'][$id]=['at'=>time(),'status'=>'working','steps'=>[]];
                $rate=$s['rates'][$peer]??['at'=>time(),'count'=>0];$rate['count']++;$s['rates'][$peer]=$rate;
                if (!$admin && (isset($s['blocked'][$peer]) || $rate['count']>10)) { $s['updates'][$id]['status']='done';$this->event($s,$id,'filtered',$peer);$this->store->save($s);return; }
                $this->store->save($s);
            }
            $send=function (string $step,int $chat,string $body,?int $route=null,?array $keyboard=null) use (&$s,$id) {
                $params=['chat_id'=>$chat,'text'=>$body,'link_preview_options'=>['is_disabled'=>true]];
                $params['reply_markup']=$keyboard??['remove_keyboard'=>true];
                return $this->step($s,$id,$step,'sendMessage',$params,$route);
            };
            $replies=TelegramReplies::read($s);
            $eventPeer=$peer;
            try {
                if ($click) {
                    // Dismiss the Telegram spinner; an expired acknowledgement must not
                    // prevent the actual reply. Content delivery still uses step journaling.
                    try { $this->api->call($c['token'],'answerCallbackQuery',['callback_query_id'=>$click['id']]); } catch (TelegramApiError) {}
                    $send('quick_reply',$peer,$replies[$click['field']]);
                } elseif (preg_match('/\A\/(start|help|id)(?:@[A-Za-z0-9_]+)?(?:\s.*)?\z/s',$text,$match)) {
                    $body=match($match[1]) {
                        'id'=>'你的 Telegram 数字 ID：'.$peer,
                        'help'=>$admin?'客服管理：对用户消息或会话卡片使用“回复”即可回信。回复会话发送 /block 可屏蔽；/unblock 数字ID 解除；回复 /who 查看用户 ID。':$replies['welcome'],
                        default=>$replies['welcome'],
                    };
                    $send('command',$peer,$body,null,$match[1]==='id' || ($admin && $match[1]==='help')?null:TelegramReplies::keyboard());
                } elseif (isset(TelegramReplies::BUTTONS[$text]) && (!$admin || !isset($m['reply_to_message']))) {
                    $send('quick_reply',$peer,$replies[TelegramReplies::BUTTONS[$text]]);
                } elseif ($admin) {
                    $route=$this->route($s,$m);$eventPeer=$route['chat_id']??$peer;
                    if (preg_match('/\A\/unblock ([1-9][0-9]{0,15})\z/',$text,$match)) {
                        try { $target=self::id($match[1]); } catch (Problem) { $target=0; }
                        if ($target) { unset($s['blocked'][$target]);$this->store->save($s);$send('command',$peer,'已解除屏蔽 #'.$target); }
                        else $send('command',$peer,'请填写正确的 Telegram 数字 ID。');
                    } elseif (!$route) $send('command',$peer,'请使用 Telegram“回复”某条用户消息或会话卡片，再输入回复内容。会话关联保留 30 天，最多 10,000 条。');
                    elseif ($text==='/who') $send('command',$peer,'该会话的用户 ID：'.$route['chat_id']);
                    elseif ($text==='/block') {
                        if (count($s['blocked'])>=10000) $send('command',$peer,'屏蔽列表已满，请先清理部分屏蔽记录。');
                        else { $s['blocked'][$route['chat_id']]=time();$this->store->save($s);$send('command',$peer,'已屏蔽 #'.$route['chat_id'].'；解除：/unblock '.$route['chat_id']); }
                    } elseif (!$this->supported($m)) $send('command',$peer,'请使用文字、图片、文件、语音、视频或贴纸回复。');
                    else {
                        $this->step($s,$id,'reply','copyMessage',['chat_id'=>$route['chat_id'],'from_chat_id'=>$peer,'message_id'=>$m['message_id']]);
                        $send('receipt',$peer,'已回复用户 #'.$route['chat_id']);
                    }
                } elseif (!$this->supported($m)) $send('command',$peer,'目前支持文字、图片、文件、语音、视频或贴纸，请换一种消息格式。');
                else {
                    $name=mb_substr(preg_replace('/[\x00-\x1f\x7f]/u',' ',is_string($m['from']['first_name']??null)?$m['from']['first_name']:'用户'),0,80);
                    $heading=$send('heading',$c['admin_id'],'客服消息 · #'.$peer."\n".$name."\n请回复这张卡片或下方消息。",$peer);
                    $this->step($s,$id,'incoming','copyMessage',['chat_id'=>$c['admin_id'],'from_chat_id'=>$peer,'message_id'=>$m['message_id'],'reply_parameters'=>['message_id'=>$heading['message_id'],'allow_sending_without_reply'=>true]],$peer);
                    // Only acknowledge a confirmed copy. Reserve the cooldown before sending;
                    // a 429 resumes this same step, an ambiguous send is never repeated.
                    if (!array_key_exists('receipt_due',$s['updates'][$id])) {
                        $due=!isset($s['reply_receipts'][$peer]) && count($s['reply_receipts'])<10000;
                        $s['updates'][$id]['receipt_due']=$due;
                        $this->store->save($s);
                    }
                    if ($s['updates'][$id]['receipt_due']) {
                        $slot=$s['reply_receipts'][$peer]??null;
                        $journal=$s['updates'][$id]['steps']['user_receipt']??null;
                        // A much later retry must not duplicate a newer receipt or exceed the
                        // map cap. Completed/uncertain journal entries still resolve via step().
                        if (!$journal && (($slot && $slot['update_id']!==$id) || (!$slot && count($s['reply_receipts'])>=10000))) {
                            $s['updates'][$id]['receipt_due']=false;
                        } else {
                            if (!$journal) {$s['reply_receipts'][$peer]=['at'=>time(),'update_id'=>$id];$this->store->save($s);}
                            $send('user_receipt',$peer,$replies['received']);
                        }
                    }
                }
                $s['updates'][$id]['status']='done';$this->event($s,$id,'delivered',$eventPeer);$this->store->save($s);
            } catch (TelegramApiError $e) {
                if ($e->apiCode===429 && !$e->uncertain) {
                    $s['updates'][$id]['status']='retry';$s['updates'][$id]['retry_at']=time()+max(1,$e->retryAfter);$this->store->save($s);throw new Problem(503,'Telegram 限流，稍后重试。');
                }
                $s['updates'][$id]['status']=$e->uncertain?'uncertain':'failed';$this->event($s,$id,$s['updates'][$id]['status'],$eventPeer,$e->apiCode);$this->store->save($s);
                // A failure receipt is best effort and never retried on duplicate updates.
                try { $this->api->call($c['token'],'sendMessage',['chat_id'=>$c['admin_id'],'text'=>'客服消息处理'.($e->uncertain?'结果待确认':'失败').'，会话 #'.$eventPeer.'，记录 '.$id.'，代码 '.$e->apiCode.'。请检查后台；发送结果不明确时先确认再回复。']); } catch (TelegramApiError) {}
            }
        });
    }
}
