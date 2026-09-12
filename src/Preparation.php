<?php
declare(strict_types=1);
namespace MTX;

/** Directory-backed preparation queue. Publishing remains an explicit action. */
final class Preparation
{
    public function __construct(private readonly App $app) {}
    public function claim(): ?array
    {
        return $this->app->store->change(function (&$s) {
            foreach ($s['releases'] as $id=>&$r) {
                if ($r['state']!=='draft' || $r['artifact_format']!=='prepared-payload-v1') continue;
                $p=$r['preparation']??['status'=>'queued','attempts'=>0];
                if ($p['status']==='failed' || ($p['status']==='processing' && ($p['started_at']??0)>time()-300)) continue;
                if (($p['attempts']??0)>=3) { $r['preparation']=$p+['error'=>'处理多次中断，请重试。']; $r['preparation']['status']='failed'; continue; }
                $token=bin2hex(random_bytes(16));
                $r['preparation']=['status'=>'processing','attempts'=>($p['attempts']??0)+1,'started_at'=>time(),'token'=>$token];
                return array_intersect_key($r,array_flip(['release_id','app_key','bundle_id','display_version','min_ios','source_sha256','source_bytes']))+
                    ['token'=>$token,'source_path'=>$this->app->object($r['source_sha256']),'work_root'=>$this->app->storage.'/preparation'];
            }
            return null;
        });
    }
    private function active(array $s,string $id,string $token): array
    {
        $r=Releases::release($s,$id);
        if ($r['state']!=='draft' || ($r['preparation']['status']??'')!=='processing' || !hash_equals($r['preparation']['token']??'', $token)) throw new Problem(409,'自动处理任务已失效。');
        return $r;
    }
    public function finish(string $id,string $token,string $path): void
    {
        $r=$this->active($this->app->store->read(),$id,$token);
        $expected=$this->app->storage.'/preparation/'.$id.'-'.$token.'/payload.tar';
        if ($path!==$expected || is_link($path) || !is_file($path) || realpath($path)!==$expected) throw new Problem(422,'自动处理输出路径异常。');
        $manifest=Packages::prepared($path,$r);
        $object=$this->app->saveObject($path);
        $this->app->store->change(function (&$s) use ($id,$token,$manifest,$object) {
            $this->active($s,$id,$token);
            $s['releases'][$id]['artifact_sha256']=$object['sha256'];
            $s['releases'][$id]['artifact_bytes']=$object['bytes'];
            $s['releases'][$id]['manifest_sha256']=$manifest;
            $s['releases'][$id]['state']='ready';
            $s['releases'][$id]['preparation']=['status'=>'complete','finished_at'=>time()];
            Store::audit($s,'TIPA 自动处理与校验完成',$id);
        });
    }
    public function fail(string $id,string $token,string $message): void
    {
        $message=Packages::label($message,200);
        $this->app->store->change(function (&$s) use ($id,$token,$message) {
            $r=$this->active($s,$id,$token);
            $s['releases'][$id]['preparation']=['status'=>'failed','error'=>$message,'attempts'=>$r['preparation']['attempts'],'finished_at'=>time()];
            Store::audit($s,'TIPA 自动处理未完成',$id);
        });
    }
    public function retry(string $key,string $id): void
    {
        $this->app->store->change(function (&$s) use ($key,$id) {
            $r=Releases::release($s,$id,$key);
            if ($r['state']!=='draft' || ($r['preparation']['status']??'')!=='failed') throw new Problem(409,'当前记录无需重试。');
            $s['releases'][$id]['preparation']=['status'=>'queued','attempts'=>0];
            Store::audit($s,'重新排队自动处理',$id);
        });
    }
}
