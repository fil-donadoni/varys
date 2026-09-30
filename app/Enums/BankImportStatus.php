<?php

namespace App\Enums;

enum BankImportStatus: string
{
    case Review = 'review';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Review => 'Da confermare',
            self::Completed => 'Completato',
        };
    }
}
