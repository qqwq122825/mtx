<?php
declare(strict_types=1);
namespace MTX;

/** The admin URL follows the physical directory, never a request-supplied include path. */
final class AdminDirectory
{
    public const MARKER = 'mtx-admin-v1';
    public static function name(string $public, string $apiMount): string
    {
        $matches=[];
        foreach (new \DirectoryIterator($public) as $entry) {
            $name=$entry->getFilename();
            if (!$entry->isDir() || $entry->isLink() || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/',$name)) continue;
            if (in_array(strtolower($name),['api','assets',strtolower(ltrim($apiMount,'/'))],true)) continue;
            $marker=$entry->getPathname().'/.mtx-admin';
            if (is_link($marker) || !is_file($marker) || trim((string)file_get_contents($marker,false,null,0,64))!==self::MARKER) continue;
            $matches[]=$name;
        }
        if (count($matches)!==1) throw new Problem(503,'请保留一份完整的后台目录，连同其中的 .mtx-admin 标识文件。');
        return $matches[0];
    }
}
