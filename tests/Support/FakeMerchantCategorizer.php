<?php

namespace Tests\Support;

use App\Services\BankImport\Llm\CategoryOption;
use App\Services\BankImport\Llm\MerchantCategorizer;
use App\Services\BankImport\Llm\MerchantInput;
use App\Services\BankImport\Llm\MerchantSuggestion;

/**
 * Records what would be sent to the LLM and answers from a merchant name → suggestion map.
 */
final class FakeMerchantCategorizer implements MerchantCategorizer
{
    /** @var list<list<MerchantInput>> */
    public array $requests = [];

    /**
     * @param  array<string, array{0: int|null, 1: float, 2?: bool}>  $answers  name => [categoryId, confidence, ambiguous]
     */
    public function __construct(private readonly array $answers = []) {}

    public function name(): string
    {
        return 'Fake';
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    /**
     * @param  list<MerchantInput>  $merchants
     * @param  list<CategoryOption>  $categories
     */
    public function categorize(array $merchants, array $categories): array
    {
        $this->requests[] = $merchants;

        return array_values(array_filter(array_map(function (MerchantInput $m): ?MerchantSuggestion {
            $answer = $this->answers[$m->name] ?? null;

            return $answer === null ? null : new MerchantSuggestion($m->id, $answer[0], $answer[1], $answer[2] ?? false);
        }, $merchants)));
    }

    /**
     * @return list<string>
     */
    public function sentNames(): array
    {
        return array_merge(...array_map(fn (array $request): array => array_map(fn (MerchantInput $m): string => $m->name, $request), $this->requests ?: [[]]));
    }
}
