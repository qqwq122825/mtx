<?php
declare(strict_types=1);
namespace MTX;

/** One managed announcement; filesystem journal shared with the bot's binding lock. */
final class TelegramAnnouncements
{
    public readonly TelegramStore $store;
    public function __construct(App $app, private readonly TelegramApi $api=new TelegramApi(), private readonly ?\Closure $clock=null)
    { $this->store=new TelegramStore($app->storage); }
    private function now(): int { return $this->clock ? ($this->clock)() : time(); }
    public static function defaults(): array
    {
        return ['revision'=>0,'card'=>[
            'text'=>"🔴 **防骗郑重声明**\n\n• 唯一原则：频道主及管理人员绝不会以任何形式私聊要求转账，或私下推荐所谓的“内部项目”。\n• 安全渠道：所有项目请统一前往下方官方自助卡网下单。\n• 防骗提醒：主动私聊你的全是骗子！请务必提高警惕，切勿向陌生人转账，谨防上当受骗。\n\n👍 **官方联系方式**\n项目合作/上架/代理/批卡/售后/解除禁言等，请务必认准以下唯一官方入口：",
            'contact'=>'','bot_contact'=>'','buttons'=>[['text'=>'官方自助卡网','url'=>''],['text'=>'官方转图频道','url'=>'']],
            'target'=>'','schedule_enabled'=>false,'schedule_mode'=>'interval','interval_minutes'=>60,'delete_enabled'=>false,'delete_minutes'=>60
        ],'next_at'=>0,'jobs'=>[],'last_tick'=>0];
    }
    public function read(): array { return $this->store->read()['announcements']??self::defaults(); }
    /** Only simple emphasis is parsed. Raw HTML and all other input stay escaped. */
    public static function format(string $text): string
    {
        $parts=preg_split('/(\*\*[^*\n]+\*\*|\*[^*\n]+\*)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE);
        $out='';
        foreach ($parts as $p) {
            if (str_starts_with($p,'**') && str_ends_with($p,'**') && strlen($p)>4) $out.='<b>'.Http::escape(substr($p,2,-2)).'</b>';
            elseif (str_starts_with($p,'*') && str_ends_with($p,'*') && strlen($p)>2) $out.='<i>'.Http::escape(substr($p,1,-1)).'</i>';
            else $out.=Http::escape($p);
        }
        return $out;
    }
    public static function html(array $card): string
    {
        $html=self::format($card['text']);
        foreach (['contact'=>'💬 唯一客服：','bot_contact'=>'🤖 双向联系：'] as $key=>$label) {
            if ($card[$key]!=='') $html.="\n".$label.'<a href="https://t.me/'.Http::escape($card[$key]).'">@'.Http::escape($card[$key]).'</a>';
        }
        return $html;
    }
    public static function target(string $value): string
    {
        if (preg_match('/\A-[1-9][0-9]{0,15}\z/',$value) && abs((int)$value)<=4503599627370495) return $value;
        if (preg_match('/\A@[A-Za-z][A-Za-z0-9_]{4,31}\z/',$value)) return $value;
        throw new Problem(422,'投递位置请填写群组 / 频道的负数 ID，或 @频道用户名。');
    }
    private static function flag(array $input,string $key): bool
    {
        $v=$input[$key]??'0';if (!in_array($v,['0','1'],true)) throw new Problem(422,'开关字段格式异常。');return $v==='1';
    }
    public static function validate(array $input): array
    {
        $card=self::defaults()['card'];
        $card['text']=trim(Http::text($input,'text',4096));
        if ($card['text']==='' || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$card['text'])) throw new Problem(422,'公告正文不能为空或包含控制字符。');
        foreach (['contact','bot_contact'] as $key) {
            $v=ltrim(trim(Http::text($input,$key,33)),'@');
            if ($v!=='' && !preg_match('/\A[A-Za-z][A-Za-z0-9_]{4,31}\z/',$v)) throw new Problem(422,'联系方式请填写有效的 Telegram 用户名。');
            $card[$key]=$v;
        }
        foreach ([0,1] as $i) {
            $label=trim(Http::text($input,'button'.($i+1).'_text',40));$url=trim(Http::text($input,'button'.($i+1).'_url',2048));
            if ($url!=='') {
                $u=parse_url($url);
                if (!$u || !filter_var($url,FILTER_VALIDATE_URL) || ($u['scheme']??'')!=='https' || isset($u['user']) || isset($u['pass']) || preg_match('/[\x00-\x20\x7f]/',$url)) throw new Problem(422,'按钮地址请使用完整的 HTTPS 链接。');
                if ($label==='') throw new Problem(422,'有链接的按钮需要填写名称。');
            }
            $card['buttons'][$i]=['text'=>$label,'url'=>$url];
        }
        $card['target']=trim(Http::text($input,'target',40));
        if ($card['target']!=='') self::target($card['target']);
        foreach (['schedule_enabled','delete_enabled'] as $key) $card[$key]=self::flag($input,$key);
        $card['schedule_mode']=Http::text($input,'schedule_mode',10,'interval');
        if (!in_array($card['schedule_mode'],['once','interval'],true)) throw new Problem(422,'定时类型异常。');
        $card['interval_minutes']=Http::integer($input,'interval_minutes');
        $card['delete_minutes']=Http::integer($input,'delete_minutes');
        if ($card['interval_minutes']<5 || $card['interval_minutes']>43200) throw new Problem(422,'重复间隔应为 5–43200 分钟。');
        if ($card['delete_minutes']<1 || $card['delete_minutes']>2820) throw new Problem(422,'自动删除应为 1–2820 分钟（最长 47 小时）。');
        $plain=html_entity_decode(strip_tags(self::html($card)),ENT_QUOTES|ENT_HTML5,'UTF-8');
        if (strlen(mb_convert_encoding($plain,'UTF-16LE','UTF-8'))/2>4096) throw new Problem(422,'正文和联系方式合计超过 Telegram 4096 字符限制。');
        return $card;
    }
    public function save(array $input): void
    {
        $card=self::validate($input);$first=trim(Http::text($input,'first_at',16));$next=0;
        if ($card['schedule_enabled']) {
            self::target($card['target']);
            if ($first!=='') {
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$first,new \DateTimeZone('Asia/Shanghai'));
                if (!$date || $date->format('Y-m-d\TH:i')!==$first || $date->getTimestamp()<$this->now()+60 || $date->getTimestamp()>$this->now()+366*86400) throw new Problem(422,'首次发送时间应在 1 分钟后至 1 年内，使用北京时间。');
                $next=$date->getTimestamp();
            } else $next=$this->now()+$card['interval_minutes']*60;
        }
        $this->store->locked(function (&$s) use ($card,$next) {
            if ($card['schedule_enabled']) $this->requireEnabled($s);
            $a=&$s['announcements'];$a??=self::defaults();
            // Editing cancels unsent retries, but never removes already scheduled deletions.
            foreach ($a['jobs'] as &$j) if ($j['status']==='retry') {$j['status']='cancelled';unset($j['card']);}unset($j);
            $a['card']=$card;$a['next_at']=$next;$a['revision']++;
            $this->store->save($s);
        });
    }
    private function requireEnabled(#[\SensitiveParameter] array $s): void
    { if (!$s['settings']['enabled'] || !$s['settings']['token']) throw new Problem(409,'请先在机器人连接页配置并启用，再发送或开启定时。'); }
    private function prune(array &$a): void
    {
        $done=[];
        foreach ($a['jobs'] as $id=>$j) if (!in_array($j['status'],['pending','retry'],true) && !in_array($j['delete_status'],['waiting','pending','retry'],true)) $done[]=$id;
        foreach (array_slice($done,0,max(0,count($done)-100)) as $id) unset($a['jobs'][$id]);
    }
    /** Unique browser nonce prevents double clicks / POST replay from sending twice. */
    public function sendNow(string $nonce,bool $test=false): string
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/',$nonce)) throw new Problem(422,'发送凭据异常，请刷新页面。');
        return $this->store->locked(function (&$s) use ($nonce,$test) {
            $this->requireEnabled($s);$a=&$s['announcements'];$a??=self::defaults();$id='manual-'.$nonce;
            if (isset($a['jobs'][$id])) return $a['jobs'][$id]['status'];
            $card=$a['card'];
            if ($test) $card['target']=(string)$s['settings']['admin_id'];else self::target($card['target']);
            $this->prune($a);$this->newJob($a,$id,$card,$test?'test':'manual');$this->store->save($s);
            $this->send($s,$id);return $a['jobs'][$id]['status'];
        });
    }
    private function newJob(array &$a,string $id,array $card,string $source): void
    {
        if (count($a['jobs'])>=1000) throw new Problem(409,'待处理公告较多，请先完成清理。');
        $a['jobs'][$id]=['at'=>$this->now(),'status'=>'retry','retry_at'=>0,'attempts'=>0,'source'=>$source,'card'=>$card,'chat_id'=>0,'message_id'=>0,'sent_at'=>0,'code'=>0,'delete_at'=>0,'delete_status'=>'none','delete_attempts'=>0];
    }
    private function fail(array &$a,array &$j,string $status,int $code): void
    {
        $j['status']=$status;$j['code']=$code;unset($j['card']);
        // A failed admin test must not interrupt an unrelated channel schedule.
        if ($j['source']!=='test') {$a['card']['schedule_enabled']=false;$a['next_at']=0;}
    }
    private function send(array &$s,string $id): void
    {
        $a=&$s['announcements'];$j=&$a['jobs'][$id];$card=$j['card'];$j['attempts']++;
        try {
            // Resolve usernames to stable negative chat IDs; never send channel announcements to a person.
            if ($j['source']==='test') $chat=(int)$card['target'];
            else {
                $r=$this->api->call($s['settings']['token'],'getChat',['chat_id'=>$card['target']]);
                if (!is_array($r) || !is_int($r['id']??null) || $r['id']>=0 || abs($r['id'])>4503599627370495 || !in_array($r['type']??'',['group','supergroup','channel'],true)) throw new TelegramApiError(400);
                $chat=$r['id'];
            }
            $params=['chat_id'=>$chat,'text'=>self::html($card),'parse_mode'=>'HTML','link_preview_options'=>['is_disabled'=>true]];
            $buttons=array_values(array_filter($card['buttons'],fn($b)=>$b['url']!==''));
            if ($buttons) $params['reply_markup']=['inline_keyboard'=>[$buttons]];
            $j['chat_id']=$chat;$j['status']='pending';$this->store->save($s);
            $result=$this->api->call($s['settings']['token'],'sendMessage',$params);
            if (!is_array($result) || !is_int($result['message_id']??null) || $result['message_id']<1) throw new TelegramApiError(0,true);
            $j['message_id']=$result['message_id'];$j['sent_at']=$this->now();$j['status']='sent';$j['code']=0;
            if ($card['delete_enabled']) {$j['delete_at']=$this->now()+$card['delete_minutes']*60;$j['delete_status']='waiting';}
            unset($j['card']);
        } catch (TelegramApiError $e) {
            if ($e->uncertain && $j['status']==='pending') $this->fail($a,$j,'uncertain',$e->apiCode);
            elseif (($e->apiCode===429 || $e->uncertain) && $j['attempts']<5) {$j['status']='retry';$j['code']=$e->apiCode;$j['retry_at']=$this->now()+max(60,$e->retryAfter);}
            else $this->fail($a,$j,'failed',$e->apiCode);
        }
        $this->store->save($s);
    }
    private function delete(array &$s,string $id): void
    {
        $j=&$s['announcements']['jobs'][$id];
        if ($this->now()>=$j['sent_at']+48*3600) {$j['delete_status']='expired';$this->store->save($s);return;}
        if ($j['delete_attempts']>=5) {$j['delete_status']='failed';$this->store->save($s);return;}
        $j['delete_status']='pending';$j['delete_attempts']++;$this->store->save($s);
        try {
            if ($this->api->call($s['settings']['token'],'deleteMessage',['chat_id'=>$j['chat_id'],'message_id'=>$j['message_id']])!==true) throw new TelegramApiError(0,true);
            $j['delete_status']='deleted';$j['code']=0;
        } catch (TelegramApiError $e) {
            $j['code']=$e->apiCode;
            $j['delete_status']=($e->uncertain || $e->apiCode===429) && $j['delete_attempts']<5 ? 'retry':'failed';
            if ($j['delete_status']==='retry') $j['delete_at']=$this->now()+max(60,$e->retryAfter);
        }
        $this->store->save($s);
    }
    /** CLI minute tick. At most 10 actions / ~30 seconds; releases bot lock between actions. */
    public function tick(): array
    {
        $start=microtime(true);$processed=0;
        do {
            $worked=$this->store->locked(function (&$s) {
                if (!isset($s['announcements'])) return false;
                $a=&$s['announcements'];$a['last_tick']=$this->now();
                foreach ($a['jobs'] as &$j) {
                    if ($j['status']==='pending') $this->fail($a,$j,'uncertain',0);
                    // Repeating deletion is harmless; after a crash a 400 is left for manual review.
                    if ($j['delete_status']==='pending') $j['delete_status']='retry';
                }unset($j);
                $this->prune($a);$this->store->save($s);
                if (!$s['settings']['token']) return false;
                foreach ($a['jobs'] as $id=>$j) if (in_array($j['delete_status'],['waiting','retry'],true) && $j['delete_at']<=$this->now()) {$this->delete($s,$id);return true;}
                if (!$s['settings']['enabled']) return false;
                foreach ($a['jobs'] as $id=>$j) if ($j['status']==='retry') {
                    if ($j['retry_at']<=$this->now()) {$this->send($s,$id);return true;}
                    return false; // Do not overtake an outstanding rate-limit retry.
                }
                if (!$a['card']['schedule_enabled'] || !$a['next_at'] || $a['next_at']>$this->now()) return false;
                $id='schedule-'.$a['revision'].'-'.$a['next_at'];$card=$a['card'];
                // Skip missed intervals rather than flooding after an outage.
                if ($card['schedule_mode']==='once') {$a['next_at']=0;$a['card']['schedule_enabled']=false;}
                else $a['next_at']=$this->now()+$card['interval_minutes']*60;
                $this->newJob($a,$id,$card,'schedule');$this->store->save($s);$this->send($s,$id);return true;
            });
            if ($worked) $processed++;
        } while ($worked && $processed<10 && microtime(true)-$start<30);
        return ['processed'=>$processed];
    }
    public function pause(): void
    {
        $this->store->locked(function (&$s) {
            $a=&$s['announcements'];$a??=self::defaults();$a['card']['schedule_enabled']=false;$a['next_at']=0;
            foreach ($a['jobs'] as &$j) if ($j['status']==='retry') {$j['status']='cancelled';unset($j['card']);}unset($j);
            $this->store->save($s);
        });
    }
}
