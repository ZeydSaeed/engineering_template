<?php

namespace App\Platform\Files;

final class ScanResult
{
    private function __construct(public readonly bool $clean, public readonly ?string $reason) {}

    public static function clean(): self
    {
        return new self(true, null);
    }

    public static function rejected(string $reason): self
    {
        return new self(false, $reason);
    }
}
