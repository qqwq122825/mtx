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
        if (!is_array($s['apps']??null) || !is_int($s['next_game_id']??null) || $s['next_game_id']<1 || $s['next_game_id']>self::MAX_ID+1) return false;
        $seen=[];
        foreach ($s['apps'] as $g) {
            $id=$g['game_id']??null;
            if (!is_int($id) || $id<1 || $id>= $s['next_game_id'] || isset($seen[$id])) return false;
            $seen[$id]=true;
        }
        return true;
    }
    public static function validate(array $s): void
    {
        if (!self::ready($s)) throw new \RuntimeException('Invalid game ID catalog; restore consistent state');
    }
    public static function allocate(array &$s): int
    {
        self::validate($s);
        if ($s['next_game_id']>self::MAX_ID) throw new Problem(503,'游戏 ID 已用尽。');
        return $s['next_game_id']++;
    }
    public static function byId(array $s,int $id): array
    {
        foreach ($s['apps'] as $g) if (($g['game_id']??null)===$id) return $g;
        throw new Problem(404,'游戏 ID 不存在。','app_not_found');
    }
    public static function rejectLegacyFields(array $input): void
    {
        if (array_key_exists('app',$input) || array_key_exists('app_key',$input)) throw new Problem(422,'游戏参数只接受 game_id。','unsupported_game_parameter');
    }
    public static function resolve(array $s,array $input): array
    {
        self::rejectLegacyFields($input);
        if (!array_key_exists('game_id',$input)) throw new Problem(422,'请指定游戏 ID。','missing_game_id');
        return self::byId($s,self::parse($input['game_id']));
    }
}
