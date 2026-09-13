<?php
declare(strict_types=1);
namespace MTX;
/** Private, bounded support history. Mutations run inside TelegramStore's relay lock. */
final class TelegramConversations
{
    public const MAX_MESSAGES=5000;
    public function __construct(private readonly TelegramStore $store) {}
    private static function binding(#[\SensitiveParameter] array $settings): string
    { return hash('sha256',($settings['token']??'').'|'.($settings['admin_id']??0)); }
    private static function empty(string $binding): array
    { return ['schema'=>1,'binding'=>$binding,'enabled'=>true,'days'=>30,'revision'=>0,'messages'=>[]]; }
    public function read(#[\SensitiveParameter] array $settings): array
    {
        $path=$this->store->directory.'/conversations.json';$binding=self::binding($settings);
        if (!is_file($path)) return self::empty($binding);
        $h=json_decode(file_get_contents($path),true,16,JSON_THROW_ON_ERROR);
        if (($h['schema']??null)!==1) throw new \RuntimeException('Conversation schema mismatch');
        if (!hash_equals($binding,$h['binding'])) return self::empty($binding);
        $cutoff=time()-$h['days']*86400;
        $h['messages']=array_filter($h['messages'],fn($m)=>$m['at']>$cutoff);
        return $h;
    }
    private function write(#[\SensitiveParameter] array $h): void
    { Store::write($this->store->directory.'/conversations.json',json_encode($h,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    private static function single(mixed $text,int $max): string
    { return is_string($text) && mb_check_encoding($text,'UTF-8') ? trim(mb_substr(preg_replace('/[\x00-\x1f\x7f\p{Cf}\p{Zl}\p{Zp}]/u',' ',$text),0,$max)) : ''; }
    /** No API credentials, downloaded media, file IDs, quoted objects or full API responses are copied. */
    public function record(#[\SensitiveParameter] array $settings,int $update,int $peer,string $direction,#[\SensitiveParameter] array $message): ?string
    {
        $h=$this->read($settings);if (!$h['enabled']) return null;
        if (!in_array($direction,['in','out'],true)) throw new \LogicException('Conversation direction invalid');
        $key=$update.':'.$direction;if (isset($h['messages'][$key])) return $key;
        $type='text';
        foreach (['photo'=>'图片','document'=>'文件','audio'=>'音频','voice'=>'语音','video'=>'视频','video_note'=>'视频留言','sticker'=>'贴纸','animation'=>'动画'] as $field=>$label) if (isset($message[$field])) {$type=$label;break;}
        $text=$message['text']??$message['caption']??'';
        $text=is_string($text) && mb_check_encoding($text,'UTF-8')?mb_substr(str_replace("\0",'', $text),0,4096):'';
        $name='';$username='';
        if ($direction==='in') {
            $name=trim(self::single($message['from']['first_name']??'',80).' '.self::single($message['from']['last_name']??'',80));
            $handle=$message['from']['username']??'';
            if (is_string($handle) && preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$handle)) $username=$handle;
        }
        $h['messages'][$key]=['id'=>$key,'update'=>$update,'peer'=>$peer,'direction'=>$direction,'at'=>time(),'type'=>$type,'text'=>$text,'name'=>$name,'username'=>$username,'status'=>'pending','message_id'=>$message['message_id'],'file_name'=>self::single($message['document']['file_name']??'',200)];
        $h['messages']=array_slice($h['messages'],-self::MAX_MESSAGES,null,true);$this->write($h);return $key;
    }
    public function status(#[\SensitiveParameter] array $settings,?string $key,string $status): void
    {
        if ($key===null) return;
        $h=$this->read($settings);if (!isset($h['messages'][$key])) return;
        if ($h['messages'][$key]['status']==='delivered' && $status!=='delivered') return;
        $h['messages'][$key]['status']=$status;$this->write($h);
    }
    public function configure(array $input): void
    {
        $days=Http::integer($input,'days');$enabled=Http::text($input,'enabled',1,'0');
        if (!in_array($days,[7,30,90],true) || !in_array($enabled,['0','1'],true)) throw new Problem(422,'请选择有效的记录开关和保留期限。');
        $this->store->locked(function ($s) use ($input,$days,$enabled) {
            $h=$this->read($s['settings']);
            if (Http::integer($input,'revision')!==$h['revision']) throw new Problem(409,'记录设置已变化，请刷新后重试。');
            $h['enabled']=$enabled==='1';$h['days']=$days;$h['revision']++;
            $h['messages']=array_filter($h['messages'],fn($m)=>$m['at']>time()-$days*86400);$this->write($h);
        });
    }
    /** Invoked by the existing minute scheduler; no separate cron is needed. */
    public function cleanup(): void
    {
        $this->store->locked(function ($s) {
            if (is_file($this->store->directory.'/conversations.json')) $this->write($this->read($s['settings']));
        });
    }
    public function listing(#[\SensitiveParameter] array $settings,array $query): array
    {
        $h=$this->read($settings);$q=mb_strtolower(trim(Http::text($query,'q',100)));$filter=Http::text($query,'status',20,'all');
        if (!in_array($filter,['all','waiting','replied','exception'],true)) throw new Problem(422,'会话筛选格式异常。');
        $page=max(1,Http::integer($query+['page'=>'1'],'page'));$rows=[];
        foreach ($h['messages'] as $m) {
            $peer=$m['peer'];$row=$rows[$peer]??['peer'=>$peer,'name'=>'用户','username'=>'','last_at'=>0,'last_update'=>-1,'last_text'=>'','last_direction'=>'','delivery'=>'pending','incoming'=>-1,'outgoing'=>-1,'count'=>0,'last_in'=>null,'last_out'=>null];
            $row['count']++;
            $slot=$m['direction']==='in'?'last_in':'last_out';
            if ($row[$slot]===null || $m['update']>=$row[$slot]['update']) {
                $row[$slot]=['update'=>$m['update'],'at'=>$m['at'],'status'=>$m['status'],'text'=>mb_substr(($m['type']==='text'?'':'['.$m['type'].'] ').$m['text'].($m['file_name']?' '.$m['file_name']:''),0,160)];
            }
            if ($m['direction']==='in' && $m['update']>=$row['incoming']) { $row['name']=$m['name']?:'用户';$row['username']=$m['username'];$row['incoming']=max($row['incoming'],$m['update']); }
            if ($m['direction']==='out' && $m['status']==='delivered') $row['outgoing']=max($row['outgoing'],$m['update']);
            if ($m['update']>=$row['last_update']) {
                $row['last_update']=$m['update'];$row['last_at']=$m['at'];$row['last_direction']=$m['direction'];$row['delivery']=$m['status'];
                $row['last_text']=mb_substr(($m['type']==='text'?'':'['.$m['type'].'] ').$m['text'].($m['file_name']?' '.$m['file_name']:''),0,160);
            }
            $rows[$peer]=$row;
        }
        $counts=['all'=>count($rows),'waiting'=>0,'replied'=>0,'exception'=>0];
        foreach ($rows as &$r) { $r['status']=$r['delivery']!=='delivered'?'exception':($r['outgoing']>=$r['incoming']?'replied':'waiting');$counts[$r['status']]++; }
        unset($r);
        $rows=array_values(array_filter($rows,fn($r)=>($filter==='all'||$r['status']===$filter) && ($q===''||str_contains(mb_strtolower($r['name'].' @'.$r['username'].' '.$r['peer'].' '.($r['last_in']['text']??'').' '.($r['last_out']['text']??'')),$q))));
        usort($rows,fn($a,$b)=>$b['last_update']<=>$a['last_update']);$total=count($rows);$page=min($page,max(1,(int)ceil($total/20)));
        return ['rows'=>array_slice($rows,($page-1)*20,20),'total'=>$total,'page'=>$page,'page_size'=>20,'counts'=>$counts,'enabled'=>$h['enabled'],'days'=>$h['days'],'revision'=>$h['revision'],'max_messages'=>self::MAX_MESSAGES];
    }
    public function detail(#[\SensitiveParameter] array $settings,array $query): array
    {
        $peer=TelegramBot::id($query['peer']??null);$page=max(1,Http::integer($query+['page'=>'1'],'page'));$h=$this->read($settings);
        $messages=array_values(array_filter($h['messages'],fn($m)=>$m['peer']===$peer));usort($messages,fn($a,$b)=>$b['update']<=>$a['update']);
        $total=count($messages);$page=min($page,max(1,(int)ceil($total/50)));
        return ['peer'=>$peer,'messages'=>array_reverse(array_slice($messages,($page-1)*50,50)),'total'=>$total,'page'=>$page,'page_size'=>50];
    }
}
