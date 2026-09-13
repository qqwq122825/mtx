<?php
declare(strict_types=1);
namespace MTX;
final class HomeSettings
{
    public const DEFAULTS=['revision'=>0,'buy_url'=>'https://www.idatariver.com/zh-cn/m/681c1436f3fead534d87b1ba?t=default','download_url'=>''];
    public static function read(Store $store): array { return array_replace(self::DEFAULTS,$store->read()['home']??[]); }
    public static function upload(App $app,array $input): void
    {
        $id=Http::integer($input,'game_id');
        GameIds::resolve($app->store->read(),['game_id'=>$id]);
        $name=$_FILES['installer']['name']??'';
        $extension=is_string($name)?strtolower(pathinfo($name,PATHINFO_EXTENSION)):'';
        if (!in_array($extension,['tipa','ipa'],true)) throw new Problem(422,'请选择安装器 TIPA 或 IPA 文件。');
        $file=$app->upload('installer',$extension);
        $zip=new \ZipArchive();
        if ($zip->open($file)!==true) throw new Problem(422,'安装器应为有效的 IPA/TIPA 压缩包。');
        try {
            $found=false;
            for ($i=0;$i<$zip->numFiles;$i++) {
                if (preg_match('~\APayload/[^/]+\.app/Info\.plist\z~',$zip->getNameIndex($i))) { $found=true;break; }
            }
            if (!$found) throw new Problem(422,'包内缺少 Payload/App.app/Info.plist，请上传编译完成的安装器。');
        } finally { $zip->close(); }
        $object=$app->saveObject($file);
        $app->store->change(function (&$s) use ($id,$object,$extension) {
            GameIds::resolve($s,['game_id'=>$id]);
            $s['home_installers'][(string)$id]=$object+['extension'=>$extension,'uploaded_at'=>gmdate('c')];
            Store::audit($s,'installer_upload',(string)$id);
        });
    }
    public static function save(Store $store,array $input): void
    {
        $values=[];
        foreach (['buy_url','download_url'] as $key) {
            $url=trim(Http::text($input,$key,2048));
            $parts=parse_url($url);
            if ($url!=='' && (!filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f]/',$url))) throw new Problem(422,'链接请填写完整的 HTTPS 地址，不含账号密码；留空可关闭按钮。');
            $values[$key]=$url;
        }
        $revision=Http::integer($input,'revision');
        $store->change(function (&$s) use ($values,$revision) {
            $current=array_replace(self::DEFAULTS,$s['home']??[]);
            if ($current['revision']!==$revision) throw new Problem(409,'设置已被修改，请刷新页面后重试。');
            $s['home']=$values+['revision'=>$revision+1];
            Store::audit($s,'home_settings','homepage');
        });
    }
}
