<?php

namespace App\Services\BankImport\Llm;

interface MerchantCategorizer
{
    /** Human readable name shown in the UI, e.g. "Claude (claude-opus-5-5)". */
    public function name(): string;

    /** Null when ready, otherwise the reason it cannot be used (shown to the user). */
    public function unavailableReason(): ?string;

    /**
     * @param  list<MerchantInput>  $merchants
     * @param  list<CategoryOption>  $categories
     * @return list<MerchantSuggestion>
     *
     * @throws CategorizerException
     */
    public function categorize(array $merchants, array $categories): array;
}
