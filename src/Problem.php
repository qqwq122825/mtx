<?php
declare(strict_types=1);
namespace MTX;
final class Problem extends \RuntimeException
{
    public function __construct(public readonly int $status, string $message, public readonly string $kind = 'invalid_request')
    { parent::__construct($message); }
}
