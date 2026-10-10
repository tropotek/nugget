<?php

namespace App\Enums;

enum AssetClass: string
{
    case Crypto = 'crypto';
    case Metal = 'metal';
    case Other = 'other';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
