<?php

namespace App\Services\BankImport;

use App\Enums\TransactionKind;
use Carbon\CarbonImmutable;

final readonly class ParsedTransaction
{
    /**
     * @param  float  $amount  Bank sign: negative = money out, positive = money in.
     * @param  string  $merchantLabel  Merchant or counterparty as written by the bank.
     */
    public function __construct(
        public TransactionKind $kind,
        public CarbonImmutable $bookingDate,
        public CarbonImmutable $operationDate,
        public float $amount,
        public string $rawDescription,
        public string $merchantLabel,
        public ?string $paymentInstrument = null,
        public ?string $bankCategory = null,
    ) {}
}
