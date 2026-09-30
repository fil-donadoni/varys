<?php

namespace App\Services\BankImport\Llm;

class NullMerchantCategorizer implements MerchantCategorizer
{
    public function name(): string
    {
        return 'Nessuno';
    }

    public function unavailableReason(): ?string
    {
        return 'Categorizzazione AI disattivata (BANK_IMPORT_LLM=none).';
    }

    public function categorize(array $merchants, array $categories): array
    {
        return [];
    }
}
