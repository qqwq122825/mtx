<?php
declare(strict_types=1);
namespace MTX;
/** Adds recursion/work limits around the upstream binary decoder. */
final class BoundedPlist extends \CFPropertyList\CFPropertyList
{
    private int $depth = 0;
    private int $steps = 0;
    private array $visiting = [];
    public function readBinaryObject()
    {
        $position = $this->pos;
        if (++$this->steps > 20000 || ++$this->depth > 64 || isset($this->visiting[$position])) throw new \RuntimeException('Plist complexity limit');
        $this->visiting[$position] = true;
        try { return parent::readBinaryObject(); }
        finally { unset($this->visiting[$position]); --$this->depth; }
    }
}
final class Plist
{
    public static function decode(string $raw): array
    {
        if (strlen($raw) > 1048576 || strlen($raw) < 8) throw new Problem(422, 'Plist 体积或结构异常。');
        set_error_handler(static function ($level, $message, $file, $line) { throw new \ErrorException($message, 0, $level, $file, $line); });
        $old = libxml_use_internal_errors(true);
        try {
            if (str_starts_with($raw, 'bplist00')) {
                if (strlen($raw) < 40) throw new \RuntimeException('Truncated plist');
                $t = substr($raw, -32);
                $width = ord($t[6]); $refs = ord($t[7]);
                $count = self::uint(substr($t, 8, 8));
                $root = self::uint(substr($t, 16, 8));
                $offset = self::uint(substr($t, 24, 8));
                if (!in_array($width, [1,2,4,8], true) || !in_array($refs, [1,2,4,8], true) || $count < 1 || $count > 10000 || $root >= $count || $offset < 8 || $offset + $count * $width > strlen($raw) - 32) throw new \RuntimeException('Plist trailer invalid');
            } else {
                if (str_contains($raw, "\0") || preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[/i', $raw)) throw new \RuntimeException('XML entities are disabled');
                $raw = preg_replace('/<!DOCTYPE[^>]*>/i', '', $raw);
                $doc = new \DOMDocument();
                if (!$doc->loadXML($raw, LIBXML_NONET) || $doc->documentElement?->tagName !== 'plist') throw new \RuntimeException('XML plist invalid');
                $queue = [[$doc->documentElement, 0]]; $nodes = 0;
                while ($queue) {
                    [$node, $depth] = array_pop($queue);
                    if (++$nodes > 20000 || $depth > 64) throw new \RuntimeException('XML complexity limit');
                    foreach ($node->childNodes as $child) if ($child instanceof \DOMElement) $queue[] = [$child, $depth + 1];
                }
            }
            $plist = new BoundedPlist();
            $plist->parse($raw);
            $value = $plist->toArray();
            if (!is_array($value) || array_is_list($value)) throw new \RuntimeException('Plist root must be a dictionary');
            return $value;
        } catch (\Throwable $e) { throw new Problem(422, '包内 Plist 解析失败或超出结构限制。', 'invalid_plist'); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($old); restore_error_handler(); }
    }
    private static function uint(string $bytes): int
    {
        $result = 0;
        foreach (str_split($bytes) as $c) {
            if ($result > intdiv(PHP_INT_MAX - ord($c), 256)) throw new \RuntimeException('Integer overflow');
            $result = $result * 256 + ord($c);
        }
        return $result;
    }
}
