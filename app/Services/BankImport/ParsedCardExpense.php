<?php

namespace App\Services\BankImport;

use Carbon\CarbonImmutable;

final readonly class ParsedCardExpense
{
    /**
     * @param  float  $amount  Positive for an expense, negative for a refund.
     */
    public function __construct(
        public CarbonImmutable $operationDate,
        public ?CarbonImmutable $bookingDate,
        public float $amount,
        public string $rawDescription,
        public string $merchantLabel,
        public ?string $paymentInstrument = null,
        public ?string $bankCategory = null,
    ) {}
}
