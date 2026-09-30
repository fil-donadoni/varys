<?php

namespace App\Services\BankImport\Llm;

final readonly class MerchantSuggestion
{
    public function __construct(
        public string $id,
        public ?int $categoryId,
        public float $confidence,
        public bool $ambiguous,
    ) {}
}
