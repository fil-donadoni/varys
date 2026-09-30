<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case ToReview = 'to_review';
    case Auto = 'auto';
    case Confirmed = 'confirmed';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::ToReview => 'Da confermare',
            self::Auto => 'Automatica',
            self::Confirmed => 'Confermata',
            self::Excluded => 'Esclusa',
        };
    }
}
