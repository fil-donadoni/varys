<?php

namespace App\Enums;

enum MerchantMatchType: string
{
    case Exact = 'exact';
    case Contains = 'contains';

    public function label(): string
    {
        return match ($this) {
            self::Exact => 'Esercente',
            self::Contains => 'Parola chiave',
        };
    }
}
