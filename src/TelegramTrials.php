<?php
declare(strict_types=1);
namespace MTX;
/** Private card inventory. All mutation shares TelegramStore's exclusive relay lock. */
final class TelegramTrials
{
    public const TITLE='领取单透测试卡';
    public const MAX_CARDS=10000;
    public const MAX_USED=100000;
    public const MAX_CLAIMS=20000;
    public function __construct(private readonly TelegramStore $store) {}
    public static function read(#[\SensitiveParameter] array $state): array
    { return $state['trials']??['revision'=>0,'activity'=>null,'cards'=>[],'used'=>[],'claims'=>[]]; }
    public static function day(int $now): string
    { return (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d'); }
    public static function button(#[\SensitiveParameter] array $state): ?array
    {
        $a=self::read($state)['activity'];
        return $a && $a['enabled'] ? ['text'=>'🎁 '.$a['title'],'callback_data'=>'mtx:trial:'.$a['id']] : null;
    }
    public static function summary(#[\SensitiveParameter] array $state): array
    {
        $t=self::read($state);$available=0;$uncertain=0;
        foreach ($t['cards'] as $card) { if ($card['status']==='available') $available++;if (in_array($card['status'],['reserved','uncertain','failed'],true)) $uncertain++; }
        return ['available'=>$available,'allocated'=>count($t['cards'])-$available,'attention'=>$uncertain];
    }
    private static function revision(array $t,#[\SensitiveParameter] array $input): void
    { if (Http::integer($input,'revision')!==$t['revision']) throw new Problem(409,'活动配置已变化，请刷新页面后操作。'); }
    public function save(#[\SensitiveParameter] array $input): void
    {
        $title=trim(Http::text($input,'title',36));
        if ($title==='' || preg_match('/[\x00-\x1f\x7f\p{Cf}\p{Zl}\p{Zp}]/u',$title)) throw new Problem(422,'活动名称请填写 1–36 个字，使用单行文字。');
        $enabled=Http::text($input,'enabled',1,'0');
        if (!in_array($enabled,['0','1'],true)) throw new Problem(422,'活动开关格式异常。');
        $this->store->locked(function (&$s) use ($input,$title,$enabled) {
            $t=self::read($s);self::revision($t,$input);
            if ($enabled==='1' && !count($t['cards'])) throw new Problem(422,'请先保存活动并导入两小时卡密，再开启领取。');
            $t['activity']=['id'=>$t['activity']['id']??bin2hex(random_bytes(8)),'title'=>$title,'enabled'=>$enabled==='1'];
            $t['revision']++;$s['trials']=$t;$this->store->save($s);
        });
    }
    public function import(#[\SensitiveParameter] array $input): array
    {
        $raw=trim(Http::text($input,'codes',150000));
        $lines=$raw===''?[]:preg_split('/\r\n|\n|\r/',$raw);
        if (!$lines || count($lines)>1000) throw new Problem(422,'每次请导入 1–1000 行卡密，一行一张。');
        $codes=[];
        foreach ($lines as $i=>$line) {
            $code=trim($line);if ($code==='') continue;
            if (!preg_match('/\A[A-Za-z0-9_-]{8,128}\z/D',$code)) throw new Problem(422,'第 '.($i+1).' 行格式异常：卡密应为 8–128 位字母、数字、下划线或短横线。');
            $codes[]=$code;
        }
        if (!$codes) throw new Problem(422,'请填写卡密，一行一张。');
        return $this->store->locked(function (&$s) use ($input,$codes) {
            $t=self::read($s);self::revision($t,$input);
            if (!$t['activity']) throw new Problem(422,'请先保存活动预设。');
            $added=0;$skipped=0;
            foreach ($codes as $code) {
                $hash=hash('sha256',$code);
                if (isset($t['cards'][$hash]) || isset($t['used'][$hash])) {$skipped++;continue;}
                if (count($t['cards'])>=self::MAX_CARDS) throw new Problem(422,'本活动库存记录已达 10,000 张，请结束旧活动后再创建。');
                $t['cards'][$hash]=['code'=>$code,'status'=>'available','peer'=>0,'day'=>'','at'=>0,'message_id'=>0];$added++;
            }
            $t['revision']++;$s['trials']=$t;$this->store->save($s);return ['added'=>$added,'skipped'=>$skipped];
        });
    }
    /** Delete only unallocated cards, rechecking ownership under the allocation lock. */
    public function deleteCards(#[\SensitiveParameter] array $input): int
    {
        $hashes=isset($input['card'])?[Http::text($input,'card',64)]:($input['cards']??[]);
        if (!is_array($hashes) || !$hashes || count($hashes)>100) throw new Problem(422,'请选择 1–100 张未分配卡密。');
        foreach ($hashes as $hash) {
            if (!is_string($hash) || !preg_match('/\A[a-f0-9]{64}\z/D',$hash)) throw new Problem(422,'卡密标识格式异常。');
        }
        $hashes=array_unique($hashes);
        return $this->store->locked(function (&$s) use ($input,$hashes) {
            $t=self::read($s);self::revision($t,$input);
            foreach ($hashes as $hash) {
                if (!isset($t['cards'][$hash])) throw new Problem(409,'部分卡密已删除，请刷新页面后操作。');
                if ($t['cards'][$hash]['status']!=='available' || $t['cards'][$hash]['peer']!==0 || isset($t['used'][$hash])) throw new Problem(409,'部分卡密已分配，请刷新后仅选择未分配库存。');
            }
            foreach ($hashes as $hash) unset($t['cards'][$hash]);
            $t['revision']++;$s['trials']=$t;$this->store->save($s);
            return count($hashes);
        });
    }
    public function pause(#[\SensitiveParameter] array $input): void
    {
        $this->store->locked(function (&$s) use ($input) {
            $t=self::read($s);self::revision($t,$input);
            if ($t['activity']) $t['activity']['enabled']=false;
            $t['revision']++;$s['trials']=$t;$this->store->save($s);
        });
    }
    public function delete(#[\SensitiveParameter] array $input): void
    {
        if (Http::text($input,'confirm_delete',20)!=='删除活动') throw new Problem(422,'请输入“删除活动”确认。');
        $this->store->locked(function (&$s) use ($input) {
            $t=self::read($s);self::revision($t,$input);
            // Keep daily limits and consumed fingerprints; deleting/recreating never reissues a used card.
            $t['activity']=null;$t['cards']=[];$t['revision']++;$s['trials']=$t;$this->store->save($s);
        });
    }
    /** Called only under the relay lock; caller must persist before sending any card. */
    public static function reserve(#[\SensitiveParameter] array &$s,int $peer,int $update,string $activity,int $now): array
    {
        $t=self::read($s);$a=$t['activity'];
        if (!$a || !$a['enabled'] || $a['id']!==$activity) {
            $s['updates'][$update]['trial']??=['kind'=>'closed'];
            return ['kind'=>'closed'];
        }
        // Replayed updates, including retries crossing midnight, always use their original decision.
        if (isset($s['updates'][$update]['trial'])) return $s['updates'][$update]['trial'];
        $t['claims']=array_filter($t['claims'],fn($c)=>$c['at']>$now-8*86400);
        $day=self::day($now);$key=$day.':'.$peer;
        if (isset($t['claims'][$key])) {
            $hash=$t['claims'][$key]['card'];
            $decision=isset($t['cards'][$hash]) && $t['cards'][$hash]['peer']===$peer ? ['kind'=>'card','card'=>$hash,'repeat'=>true] : ['kind'=>'claimed'];
        } elseif (count($t['claims'])>=self::MAX_CLAIMS || count($t['used'])>=self::MAX_USED) {
            $decision=['kind'=>'busy'];
        } else {
            $decision=['kind'=>'empty'];
            foreach ($t['cards'] as $hash=>&$card) {
                if ($card['status']!=='available') continue;
                $card['status']='reserved';$card['peer']=$peer;$card['day']=$day;$card['at']=$now;
                $t['used'][$hash]=true;$t['claims'][$key]=['card'=>$hash,'at'=>$now];
                $decision=['kind'=>'card','card'=>$hash,'repeat'=>false];break;
            }
            unset($card);
        }
        $s['trials']=$t;$s['updates'][$update]['trial']=$decision;return $decision;
    }
    public static function body(#[\SensitiveParameter] array $s,array $decision,int $peer): string
    {
        if ($decision['kind']==='card') {
            $t=self::read($s);$card=$t['cards'][$decision['card']]??null;
            if (!$card || $card['peer']!==$peer) return '本次领取记录已归档，请联系客服。今天的领取次数保持不变。';
            return ($decision['repeat']?'🎁 你今天已领取，下面是同一张卡：':'🎁 领取成功 · 两小时测试卡')."\n\n".$card['code']."\n\n每个 Telegram 账号每天限领 1 张，北京时间 00:00 重置。\n卡片时长：2 小时，生效与到期以卡密系统为准。请妥善保管，不要公开转发。";
        }
        return match($decision['kind']) {
            'closed'=>'本活动已暂停或结束，暂不开放领取。发送 /start 查看当前入口。',
            'claimed'=>'你今天已领取过测试卡，请在北京时间明天 00:00 后再来。',
            'empty'=>'测试卡暂时领完了，请稍后再试。此次未扣除领取次数。',
            default=>'领取服务繁忙，请稍后再试。此次未扣除领取次数。',
        };
    }
    public static function result(#[\SensitiveParameter] array &$s,int $update,string $status,int $message=0): void
    {
        $decision=$s['updates'][$update]['trial']??null;
        if (($decision['kind']??'')!=='card' || !isset($s['trials']['cards'][$decision['card']])) return;
        $card=&$s['trials']['cards'][$decision['card']];
        // A later re-display failure must not downgrade an earlier confirmed delivery.
        if ($card['status']==='delivered' && $status!=='delivered') return;
        $card['status']=$status;if ($message) $card['message_id']=$message;
    }
}
