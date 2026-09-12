<?php
declare(strict_types=1);
namespace MTX;
/** Local filesystem only: stable lock inode + atomic JSON replacement. */
final class Store
{
    public function __construct(public readonly string $directory) {}
    public static function write(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.write-');
        if ($temporary === false) throw new \RuntimeException('Temporary file creation failed');
        try {
            chmod($temporary, 0600);
            $handle = fopen($temporary, 'wb');
            if (!$handle) throw new \RuntimeException('File open failed');
            try {
                $offset = 0;
                while ($offset < strlen($bytes)) {
                    $written = fwrite($handle, substr($bytes, $offset));
                    if ($written === false || $written === 0) throw new \RuntimeException('File write failed');
                    $offset += $written;
                }
                if (!fflush($handle) || !fsync($handle)) throw new \RuntimeException('File sync failed');
            } finally { fclose($handle); }
            if (!rename($temporary, $path)) throw new \RuntimeException('Atomic rename failed');
        } finally { if (is_file($temporary)) unlink($temporary); }
    }
    public function read(): array { return $this->locked(false, fn(array &$data) => $data); }
    public function change(callable $callback): mixed { return $this->locked(true, $callback); }
    private function locked(bool $write, callable $callback): mixed
    {
        $lock = fopen($this->directory . '/state.lock', 'c');
        if (!$lock || !flock($lock, $write ? LOCK_EX : LOCK_SH)) throw new \RuntimeException('Lock failed');
        try {
            $raw = file_get_contents($this->directory . '/state.json');
            if ($raw === false) throw new \RuntimeException('State read failed');
            $data = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
            if (($data['schema'] ?? null) !== 1) throw new \RuntimeException('Unsupported state schema');
            $result = $callback($data);
            if ($write) self::write($this->directory . '/state.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            return $result;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    public static function audit(array &$state, string $action, string $target): void
    {
        $state['audit'][] = ['time' => gmdate('c'), 'action' => $action, 'target' => $target];
        $state['audit'] = array_slice($state['audit'], -1000);
    }
}
