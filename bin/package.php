<?php
declare(strict_types=1);
if (PHP_SAPI!=='cli') exit(1);
$root=dirname(__DIR__);
if (!is_dir($root.'/vendor')) { fwrite(STDERR,"请先 composer install。\n"); exit(1); }
if (!is_dir($root.'/build')) mkdir($root.'/build',0700,true);
$zip=new ZipArchive();
$path=$root.'/build/mtx-update-center.zip';
if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) exit(1);
// Positive allowlist: never include runtime state, local configuration, packages or credentials.
foreach (['src','public','bin','deploy','docs','vendor','preparer'] as $dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir,FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink() && !str_contains($file->getPathname(),'/__pycache__/') && !str_contains($file->getPathname(),'/native/build/')) $zip->addFile($file->getPathname(),substr($file->getPathname(),strlen($root)+1));
    }
}
foreach (['README.md','composer.json','composer.lock'] as $name) $zip->addFile($root.'/'.$name,$name);
$zip->close();
echo $path."\n";
