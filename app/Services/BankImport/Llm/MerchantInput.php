<?php

namespace App\Services\BankImport\Llm;

final readonly class MerchantInput
{
    /**
     * @param  string  $id  Random, single-use identifier: only the app can map it back.
     * @param  string  $direction  "uscita" or "entrata".
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $direction,
        public ?string $bankCategory = null,
    ) {}
}
