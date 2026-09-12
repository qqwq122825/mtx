<?php
declare(strict_types=1);
namespace MTX;
final class App
{
    public readonly Store $store;
    public readonly array $config;
    public readonly string $storage;
    public function __construct()
    {
        $root = dirname(__DIR__);
        $path = getenv('MTX_CONFIG') ?: $root . '/config.local.php';
        // Credentials can be rotated atomically between requests, including within
        // the same filesystem timestamp second. Do not serve a cached password hash.
        clearstatcache(true, $path);
        if (function_exists('opcache_invalidate')) opcache_invalidate($path, true);
        if (!is_file($path)) throw new Problem(503, '服务尚未初始化，请先运行 bin/setup.php。', 'not_configured');
        $this->config = require $path;
        if (!preg_match('/\A\/[a-z0-9-]{16,64}\z/D', $this->config['mount_path'] ?? '')) throw new \RuntimeException('Run bin/upgrade.php to configure private mount');
        $this->storage = rtrim($this->config['storage'], '/');
        $this->store = new Store($this->storage);
        if (!is_dir($this->storage . '/objects')) throw new \RuntimeException('Storage missing');
        $real = realpath($this->storage);
        $public = realpath($root . '/public');
        if (!$real || str_starts_with($real . '/', $public . '/')) throw new \RuntimeException('Storage must be outside public');
        if (PHP_SAPI !== 'cli' && !$this->config['local_http'] && (($_SERVER['HTTPS'] ?? '') !== 'on')) {
            throw new Problem(400, '请通过 HTTPS 访问。', 'https_required');
        }
    }
    public function path(string $path): string { return $this->config['mount_path'] . $path; }
    public function url(string $path): string { return rtrim($this->config['base_url'], '/') . $this->path($path); }
    public function object(string $sha): string
    {
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $sha)) throw new Problem(404, '文件不存在。');
        return $this->storage . '/objects/' . $sha;
    }
    public function saveObject(string $file): array
    {
        $size = filesize($file);
        $sha = hash_file('sha256', $file);
        if (!$sha || !$size) throw new Problem(422, '上传文件为空。');
        $dest = $this->object($sha);
        if (!is_file($dest)) {
            $tmp = tempnam($this->storage . '/objects', '.object-');
            try {
                if (!$tmp || !copy($file, $tmp)) throw new \RuntimeException('Object copy failed');
                chmod($tmp, 0600);
                if (!hash_equals($sha, hash_file('sha256', $tmp))) throw new \RuntimeException('Object hash changed');
                $h = fopen($tmp, 'r+');
                try { if (!$h || !fsync($h)) throw new \RuntimeException('Object sync failed'); }
                finally { if ($h) fclose($h); }
                if (!rename($tmp, $dest)) throw new \RuntimeException('Object rename failed');
            } finally { if ($tmp && is_file($tmp)) unlink($tmp); }
        } elseif (!hash_equals($sha, hash_file('sha256', $dest))) throw new \RuntimeException('Stored object corrupted');
        return ['sha256' => $sha, 'bytes' => $size];
    }
    public function upload(string $field, string $extension): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null)) throw new Problem(422, '请选择完整的上传文件。');
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new Problem(422, '上传未完成或超过服务器额度，请重试。');
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== $extension) throw new Problem(422, '文件扩展名应为 .' . $extension . '。');
        if (!is_uploaded_file($file['tmp_name'])) throw new Problem(422, '上传来源无效。');
        $size = filesize($file['tmp_name']);
        if (!$size || $size > $this->config['max_upload_bytes']) throw new Problem(413, '文件大小超过上传额度或文件为空。');
        return $file['tmp_name'];
    }
}
