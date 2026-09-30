<?php

namespace App\Enums;

enum Bank: string
{
    case Intesa = 'intesa';
    case Ing = 'ing';

    public function label(): string
    {
        return match ($this) {
            self::Intesa => 'Intesa Sanpaolo',
            self::Ing => 'ING',
        };
    }
}
