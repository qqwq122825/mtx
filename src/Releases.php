<?php
declare(strict_types=1);
namespace MTX;
final class Releases
{
    public function __construct(private readonly App $app) {}
    public static function game(array $s, string $key): array
    {
        if (!isset($s['apps'][$key])) throw new Problem(404, '游戏配置不存在。', 'app_not_found');
        return $s['apps'][$key];
    }
    public static function release(array $s, string $id, ?string $key = null): array
    {
        $r = $s['releases'][$id] ?? null;
        if (!$r || ($key !== null && $r['app_key'] !== $key)) throw new Problem(404, '版本记录不存在。');
        return $r;
    }
    public function createGame(array $input): string
    {
        $key = Http::text($input, 'app_key', 60);
        if (!preg_match('/\A[a-z][a-z0-9-]{2,59}\z/', $key)) throw new Problem(422, '固定标识使用 3–60 位小写字母、数字和连字符。');
        $bundle = Http::text($input, 'bundle_id', 180);
        if (!preg_match('/\A[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+\z/', $bundle)) throw new Problem(422, '请输入正确的 Bundle ID。');
        $format = Http::text($input, 'format', 40);
        if (!in_array($format, ['tipa','prepared-payload-v1'], true)) throw new Problem(422, '分发格式无效。');
        $name = Packages::label(Http::text($input, 'name', 120), 120);
        $profile = Http::text($input, 'profile_id', 80);
        if (!preg_match('/\A[a-z0-9][a-z0-9-]{2,79}\z/', $profile)) throw new Problem(422, '产物契约标识格式异常。');
        $this->app->store->change(function (&$s) use ($key,$bundle,$format,$name,$profile) {
            if (isset($s['apps'][$key])) throw new Problem(409, '此游戏标识已存在。');
            $s['apps'][$key] = ['app_key'=>$key,'name'=>$name,'bundle_id'=>$bundle,'format'=>$format,'profile_id'=>$profile,'enabled'=>true,'current_release_id'=>null,'next_sequence'=>1,'revision'=>0];
            Store::audit($s, '新增游戏配置', $key);
        });
        return $key;
    }
    public function upload(string $key, string $file, array $input): string
    {
        $g = self::game($this->app->store->read(), $key);
        $meta = Packages::tipa($file, $g['bundle_id']);
        $maxOS = Packages::version(Http::text($input, 'max_ios', 12));
        $minClient = Packages::version(Http::text($input, 'min_installer_version', 12));
        if (version_compare($maxOS, $meta['min_ios'], '<')) throw new Problem(422, '最高系统版本低于包内最低系统要求。');
        $log = Http::text($input, 'changelog', 5000);
        if (trim($log) === '') throw new Problem(422, '请填写本次更新说明。');
        $source = $this->app->saveObject($file);
        $id = bin2hex(random_bytes(16));
        $r = $meta + ['release_id'=>$id,'app_key'=>$key,'sequence'=>null,'state'=>$g['format']==='tipa'?'ready':'draft','source_sha256'=>$source['sha256'],'source_bytes'=>$source['bytes'],'artifact_sha256'=>$g['format']==='tipa'?$source['sha256']:null,'artifact_bytes'=>$g['format']==='tipa'?$source['bytes']:null,'artifact_format'=>$g['format'],'profile_id'=>$g['profile_id'],'max_ios'=>$maxOS,'min_installer_version'=>$minClient,'changelog'=>$log,'created_at'=>gmdate('c'),'published_at'=>null];
        return $this->app->store->change(function (&$s) use ($r,$key,$id,$source) {
            self::game($s,$key);
            foreach ($s['releases'] as $existing) if ($existing['app_key']===$key && $existing['source_sha256']===$source['sha256'] && in_array($existing['state'],['draft','ready'],true)) throw new Problem(409, '此源包已有待发布版本，请继续处理原记录。');
            $s['releases'][$id]=$r;
            Store::audit($s, '上传 TIPA', $key . ' / ' . $id);
            return $id;
        });
    }
    public function attach(string $key, string $id, string $file): void
    {
        $r=self::release($this->app->store->read(),$id,$key);
        if ($r['state']!=='draft' || $r['artifact_format']!=='prepared-payload-v1') throw new Problem(409,'仅待准备版本接收 TAR 产物。');
        $manifestSHA=Packages::prepared($file,$r);
        $artifact=$this->app->saveObject($file);
        $this->app->store->change(function (&$s) use ($key,$id,$artifact,$manifestSHA) {
            $r=self::release($s,$id,$key);
            if ($r['state']!=='draft') throw new Problem(409,'版本状态已变化，请刷新。');
            $s['releases'][$id]['artifact_sha256']=$artifact['sha256'];
            $s['releases'][$id]['artifact_bytes']=$artifact['bytes'];
            $s['releases'][$id]['manifest_sha256']=$manifestSHA;
            $s['releases'][$id]['state']='ready';
            Store::audit($s,'准备产物结构与摘要校验通过',$key.' / '.$id);
        });
    }
    public function publish(string $key, string $id, int $revision): void
    {
        $this->app->store->change(function (&$s) use ($key,$id,$revision) {
            $g=self::game($s,$key); $r=self::release($s,$id,$key);
            if ($r['state']==='published' && $g['current_release_id']===$id) return; // retry is idempotent
            if ($g['revision']!==$revision) throw new Problem(409,'其他操作已更新线上版本，请刷新后确认。','revision_conflict');
            if ($r['state']!=='ready' || !$g['enabled']) throw new Problem(409,'游戏已停用或版本尚未准备完成。');
            foreach (['source','artifact'] as $part) {
                $path=$this->app->object($r[$part.'_sha256']);
                if (!is_file($path) || filesize($path)!==$r[$part.'_bytes'] || !hash_equals($r[$part.'_sha256'],hash_file('sha256',$path))) throw new Problem(409,'发布文件缺失或摘要异常。');
            }
            $s['releases'][$id]['sequence']=$g['next_sequence'];
            $s['releases'][$id]['published_at']=gmdate('c');
            $s['releases'][$id]['state']='published';
            $s['apps'][$key]['next_sequence']++;
            $s['apps'][$key]['current_release_id']=$id;
            $s['apps'][$key]['revision']++;
            Store::audit($s,'发布版本 #'.$g['next_sequence'],$key.' / '.$id);
        });
    }
    public function withdraw(string $key,string $id,int $revision): void
    {
        $this->app->store->change(function (&$s) use ($key,$id,$revision) {
            $g=self::game($s,$key); $r=self::release($s,$id,$key);
            if ($r['state']==='withdrawn') return;
            if ($g['revision']!==$revision) throw new Problem(409,'配置已变化，请刷新后确认。');
            $s['releases'][$id]['state']='withdrawn';
            if ($g['current_release_id']===$id) $s['apps'][$key]['current_release_id']=null;
            $s['apps'][$key]['revision']++;
            Store::audit($s,'下架版本',$key.' / '.$id);
        });
    }
    public function republishDraft(string $key,string $id): void
    {
        $this->app->store->change(function (&$s) use ($key,$id) {
            $r=self::release($s,$id,$key);
            if (!in_array($r['state'],['published','withdrawn'],true) || !$r['artifact_sha256']) throw new Problem(409,'仅完整历史版本可复制为新发布草稿。');
            $new=bin2hex(random_bytes(16));
            $r['release_id']=$new; $r['state']='ready'; $r['sequence']=null; $r['published_at']=null; $r['created_at']=gmdate('c');
            $r['changelog']='重新发布历史内容：' . $r['changelog'];
            $s['releases'][$new]=$r;
            Store::audit($s,'复制历史内容为草稿',$key.' / '.$new);
        });
    }
    public function toggle(string $key,int $revision): void
    {
        $this->app->store->change(function (&$s) use ($key,$revision) {
            $g=self::game($s,$key);
            if ($g['revision']!==$revision) throw new Problem(409,'配置已变化，请刷新。');
            $s['apps'][$key]['enabled']=!$g['enabled']; $s['apps'][$key]['revision']++;
            Store::audit($s,$g['enabled']?'停用游戏接口':'启用游戏接口',$key);
        });
    }
    public static function wire(array $r): array
    {
        $fields=['release_id','sequence','display_version','bundle_version','bundle_id','profile_id','artifact_format','artifact_bytes','artifact_sha256','source_sha256','source_bytes','manifest_sha256','min_installer_version','min_ios','max_ios','changelog','published_at'];
        return array_intersect_key($r,array_flip($fields))+['artifact_id'=>$r['artifact_sha256']];
    }
}
