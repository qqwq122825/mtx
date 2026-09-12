<?php
declare(strict_types=1);
namespace MTX;
/** Stable, server-assigned numeric identities. All allocation runs under Store::change. */
final class GameIds
{
    public const MAX_ID = 2147483647;
    public static function parse(mixed $value): int
    {
        if ((!is_int($value) && !is_string($value)) || !preg_match('/\A[1-9][0-9]{0,9}\z/',(string)$value) || (int)$value>self::MAX_ID) throw new Problem(422,'游戏 ID 应为正整数。','invalid_game_id');
        return (int)$value;
    }
    public static function ready(array $s): bool
    {
        if (!is_int($s['next_game_id']??null) || $s['next_game_id']<1 || $s['next_game_id']>self::MAX_ID+1) return false;
        $seen=[];
        foreach ($s['apps'] as $g) {
            $id=$g['game_id']??null;
            if (!is_int($id) || $id<1 || $id>= $s['next_game_id'] || isset($seen[$id])) return false;
            $seen[$id]=true;
        }
        return true;
    }
    public static function migrate(array &$s): void
    {
        $used=[]; $highest=0;
        foreach ($s['apps'] as &$g) if (isset($g['game_id'])) {
            $id=self::parse($g['game_id']); $g['game_id']=$id;
            if (isset($used[$id])) throw new \RuntimeException('Duplicate stored game ID; restore consistent state');
            $used[$id]=true; $highest=max($highest,$id);
        }
        unset($g);
        $next=$s['next_game_id']??1;
        if (!is_int($next) || $next<1 || $next>self::MAX_ID+1) throw new \RuntimeException('Invalid game ID counter');
        $next=max($next,$highest+1);
        foreach ($s['apps'] as &$g) if (!isset($g['game_id'])) {
            if ($next>self::MAX_ID) throw new Problem(503,'游戏 ID 已用尽。');
            $g['game_id']=$next++;
        }
        $s['next_game_id']=$next;
    }
    public static function allocate(array &$s): int
    {
        self::migrate($s);
        if ($s['next_game_id']>self::MAX_ID) throw new Problem(503,'游戏 ID 已用尽。');
        return $s['next_game_id']++;
    }
    public static function byId(array $s,int $id): array
    {
        foreach ($s['apps'] as $g) if (($g['game_id']??null)===$id) return $g;
        throw new Problem(404,'游戏 ID 不存在。','app_not_found');
    }
    public static function resolve(array $s,array $input,string $legacyField): array
    {
        $legacy=Http::text($input,$legacyField,60);
        if (array_key_exists('game_id',$input)) {
            $g=self::byId($s,self::parse($input['game_id']));
            if ($legacy!=='' && $legacy!==$g['app_key']) throw new Problem(422,'游戏 ID 与旧标识不匹配。','game_identity_conflict');
            return $g;
        }
        if ($legacy==='') throw new Problem(422,'请指定游戏 ID。','missing_game_id');
        return Releases::game($s,$legacy); // Existing installers remain compatible.
    }
}
