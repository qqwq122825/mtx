<?php
declare(strict_types=1);
namespace MTX;
final class Packages
{
    public static function tipa(string $path, string $expected): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::RDONLY | \ZipArchive::CHECKCONS) !== true) throw new Problem(422, 'TIPA 不是完整的 ZIP 归档。');
        try {
            if ($zip->numFiles < 2 || $zip->numFiles > 25000) throw new Problem(422, '包内文件数量异常。');
            $seen = []; $roots = []; $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name']; $canonical = strtolower(rtrim($name, '/'));
                if (preg_match('~[\\\\\x00-\x1f\x7f]|\A/|(?:\A|/)\.{1,2}(?:/|\z)|//~', $name) || isset($seen[$canonical])) throw new Problem(422, '包内存在重复或异常路径。');
                $seen[$canonical] = true;
                $zip->getExternalAttributesIndex($i, $opsys, $attributes);
                $type = ($attributes >> 16) & 0170000;
                if (!in_array($type, [0, 0100000, 0040000], true) || ($stat['encryption_method'] ?? 0) !== 0) throw new Problem(422, '包内包含链接、特殊文件或加密条目。');
                $total += $stat['size'];
                if ($total > 268435456 || $stat['size'] > 134217728) throw new Problem(413, '包展开后的体积超过限制。');
                if (preg_match('~\APayload/[^/]+\.app/Info\.plist\z~', $name)) $roots[] = $name;
                if (str_ends_with($name, '/')) continue;
                $stream = $zip->getStream($name);
                if (!$stream) throw new Problem(422, '包内文件读取失败。');
                try {
                    $bytes = 0; $crc = hash_init('crc32b');
                    while (!feof($stream)) {
                        $chunk = fread($stream, 1048576);
                        if ($chunk === false || ($chunk === '' && !feof($stream))) throw new Problem(422, '归档读取中断。');
                        $bytes += strlen($chunk);
                        if ($bytes > $stat['size']) throw new Problem(413, '归档文件长度不符。');
                        hash_update($crc, $chunk);
                    }
                    if ($bytes !== $stat['size'] || hash_final($crc) !== sprintf('%08x', $stat['crc'])) throw new Problem(422, '归档 CRC 或文件长度校验失败。');
                } finally { fclose($stream); }
            }
            if (count($roots) !== 1) throw new Problem(422, 'TIPA 应只有一个主应用。');
            $s = $zip->statName($roots[0]);
            if ($s['size'] > 1048576) throw new Problem(422, 'Info.plist 超出大小限制。');
            $info = Plist::decode($zip->getFromName($roots[0]));
            if (($info['CFBundleIdentifier'] ?? null) !== $expected) throw new Problem(422, 'Bundle ID 与所选游戏不匹配。', 'bundle_mismatch');
            $exe = $info['CFBundleExecutable'] ?? '';
            if (!is_string($exe) || !preg_match('/\A[^\/\\\\\x00-\x1f]{1,200}\z/u', $exe) || in_array($exe, ['.', '..'], true)) throw new Problem(422, '主程序名称异常。');
            $main = $zip->statName(substr($roots[0], 0, -10) . $exe);
            if (!$main || $main['size'] === 0) throw new Problem(422, '包内缺少主程序。');
            $version = self::label($info['CFBundleShortVersionString'] ?? '', 80);
            $build = self::label($info['CFBundleVersion'] ?? '', 80);
            $minimum = self::version($info['MinimumOSVersion'] ?? '');
            return ['bundle_id' => $expected, 'display_name' => self::label($info['CFBundleDisplayName'] ?? $info['CFBundleName'] ?? $expected, 160), 'display_version' => $version, 'bundle_version' => $build, 'min_ios' => $minimum];
        } finally { $zip->close(); }
    }
    public static function prepared(string $path, array $release): string
    {
        $h = fopen($path, 'rb');
        if (!$h) throw new Problem(422, 'TAR 读取失败。');
        $files = []; $manifest = null; $total = 0; $finished = false;
        try {
            while (!feof($h)) {
                $header = fread($h, 512);
                if (strlen($header) !== 512) throw new Problem(422, 'TAR 头部不完整。');
                if ($header === str_repeat("\0", 512)) {
                    $next = fread($h, 512);
                    if ($next !== str_repeat("\0", 512)) throw new Problem(422, 'TAR 结束标记异常。');
                    while (!feof($h)) { $tail = fread($h, 8192); if (trim($tail, "\0") !== '') throw new Problem(422, 'TAR 尾部含额外内容。'); }
                    $finished = true; break;
                }
                $name = rtrim(substr($header, 0, 100), "\0");
                $size = self::octal(substr($header, 124, 12));
                $sum = array_sum(array_map('ord', str_split(substr_replace($header, '        ', 148, 8))));
                if ($sum !== self::octal(substr($header, 148, 8)) || substr($header, 257, 5) !== 'ustar' || trim(substr($header, 345, 155), "\0") !== '' || !in_array($header[156], ["\0", '0'], true)) throw new Problem(422, '只接受规范 USTAR 普通文件。');
                if (!in_array($name, ['Main','MTXMenuIcons.ttf','Manifest.plist'], true) || isset($files[$name])) throw new Problem(422, 'TAR 文件列表与当前安装契约不符。');
                $total += $size;
                if ($size < 1 || $size > 134217728 || $total > 268435456 || ($name === 'Manifest.plist' && $size > 1048576)) throw new Problem(413, 'TAR 文件体积异常。');
                $digest = hash_init('sha256'); $raw = ''; $left = $size;
                while ($left > 0) {
                    $chunk = fread($h, min($left, 1048576));
                    if ($chunk === false || $chunk === '') throw new Problem(422, 'TAR 内容截断。');
                    hash_update($digest, $chunk); $left -= strlen($chunk);
                    if ($name === 'Manifest.plist') $raw .= $chunk;
                }
                $padding = (512 - $size % 512) % 512;
                if ($padding && fread($h, $padding) !== str_repeat("\0", $padding)) throw new Problem(422, 'TAR 填充异常。');
                $files[$name] = hash_final($digest);
                if ($name === 'Manifest.plist') $manifest = Plist::decode($raw);
            }
        } finally { fclose($h); }
        if (!$finished || count($files) !== 3 || !is_array($manifest)) throw new Problem(422, 'TAR 缺少必需资源。');
        if (($manifest['SchemaVersion'] ?? null) !== 1 || ($manifest['BundleIdentifier'] ?? null) !== $release['bundle_id'] || ($manifest['SourceSHA256'] ?? null) !== $release['source_sha256'] || ($manifest['Version'] ?? null) !== $release['display_version']) throw new Problem(422, '准备产物与本次 TIPA 的身份、版本或摘要不匹配。');
        $hashes = $manifest['Files'] ?? [];
        if (!is_array($hashes) || count($hashes) !== 2) throw new Problem(422, '准备清单文件表异常。');
        foreach (['Main','MTXMenuIcons.ttf'] as $name) if (($hashes[$name] ?? null) !== $files[$name]) throw new Problem(422, '准备产物内部摘要校验失败。');
        $overrides = $manifest['InfoOverrides'] ?? null;
        if (!is_array($overrides) || array_diff(array_keys($overrides), ['NSAppTransportSecurity','CADisableMinimumFrameDurationOnPhone','UIRequiresFullScreen','UISupportedInterfaceOrientations','UISupportedInterfaceOrientations~ipad','UIStatusBarHidden','UIViewControllerBasedStatusBarAppearance','UIAppFonts'])) throw new Problem(422, '准备清单配置项尚未适配。');
        return $files['Manifest.plist'];
    }
    private static function octal(string $raw): int
    {
        $value = trim($raw, "\0 ");
        if (!preg_match('/\A[0-7]{1,11}\z/', $value)) throw new Problem(422, 'TAR 数字字段异常。');
        return intval($value, 8);
    }
    public static function label(mixed $value, int $max): string
    {
        if (!is_string($value) || trim($value) === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1f\x7f]/', $value)) throw new Problem(422, '名称或版本字段为空、过长或格式异常。');
        return trim($value);
    }
    public static function version(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A\d{1,3}(?:\.\d{1,3}){0,2}\z/', $value)) throw new Problem(422, '系统或安装器版本格式应为 1.2.3。');
        return implode('.', array_pad(array_map('intval', explode('.', $value)), 3, 0));
    }
}
