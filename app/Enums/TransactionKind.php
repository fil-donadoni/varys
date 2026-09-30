<?php

namespace App\Enums;

enum TransactionKind: string
{
    case Card = 'card';
    case DirectDebit = 'direct_debit';
    case TransferIn = 'transfer_in';
    case TransferOut = 'transfer_out';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Card => 'Carta',
            self::DirectDebit => 'Addebito diretto',
            self::TransferIn => 'Bonifico ricevuto',
            self::TransferOut => 'Bonifico inviato',
            self::Other => 'Altro',
        };
    }

    public function isTransfer(): bool
    {
        return $this === self::TransferIn || $this === self::TransferOut;
    }
}
