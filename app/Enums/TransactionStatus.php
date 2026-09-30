<?php

namespace App\Enums;

enum TransactionStatus: string
{
    case ToReview = 'to_review';
    case Auto = 'auto'; // has a category, waiting for the import to be confirmed
    case Confirmed = 'confirmed';
    case Excluded = 'excluded';

    public function label(): string
    {
        return match ($this) {
            self::ToReview => 'Da confermare',
            self::Auto => 'Pronta',
            self::Confirmed => 'Confermata',
            self::Excluded => 'Esclusa',
        };
    }
}
