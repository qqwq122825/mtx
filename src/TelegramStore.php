<?php
declare(strict_types=1);
namespace MTX;
/** Separate lock/state so Telegram requests never hold the package publishing lock. */
final class TelegramStore
{
    public readonly string $directory;
    public function __construct(string $storage)
    {
        $this->directory=$storage.'/telegram';
        if (!is_dir($this->directory) && !@mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new \RuntimeException('Telegram storage missing');
    }
    public static function emptyState(): array
    { return ['schema'=>1,'settings'=>['token'=>'','admin_id'=>0,'enabled'=>false,'secret'=>'','username'=>''],'updates'=>[],'routes'=>[],'blocked'=>[],'rates'=>[],'events'=>[]]; }
    public function save(#[\SensitiveParameter] array $state): void
    { Store::write($this->directory.'/state.json',json_encode($state,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)); }
    public function locked(callable $callback): mixed
    {
        $lock=fopen($this->directory.'/state.lock','c');
        if (!$lock || !flock($lock,LOCK_EX)) throw new \RuntimeException('Telegram lock failed');
        try {
            $state=$this->read();
            return $callback($state);
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
    // Store::write publishes via atomic rename, so readers see a complete snapshot.
    // Public header checks and the admin page should not wait on outbound API calls.
    public function read(): array
    {
        $path=$this->directory.'/state.json';
        $state=is_file($path)?json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR):self::emptyState();
        if (($state['schema']??null)!==1) throw new \RuntimeException('Telegram state schema mismatch');
        return $state;
    }
}
