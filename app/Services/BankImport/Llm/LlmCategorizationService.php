<?php

namespace App\Services\BankImport\Llm;

use App\Enums\CategorizationSource;
use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\Category;
use Illuminate\Support\Str;

/**
 * Sends the anonymized merchants to the configured LLM and stores its suggestions.
 */
class LlmCategorizationService
{
    public function __construct(
        private readonly MerchantCategorizer $categorizer,
        private readonly LlmPayloadBuilder $payloadBuilder,
    ) {}

    /**
     * @param  list<string>  $excludedMerchantKeys  Merchants the user chose not to send.
     *
     * @throws CategorizerException
     */
    public function run(BankImport $import, array $excludedMerchantKeys = []): void
    {
        $reason = $this->categorizer->unavailableReason();

        if ($reason !== null) {
            throw new CategorizerException($reason);
        }

        $toSend = array_values(array_filter(
            $this->payloadBuilder->preview($import)['send'],
            fn (array $item): bool => ! in_array($item['merchant_key'], $excludedMerchantKeys, true),
        ));

        $categories = array_values(Category::query()->orderBy('type')->orderBy('sort_order')->get()
            ->map(fn (Category $c): CategoryOption => new CategoryOption($c->id, $c->name, $c->type)) // @phpstan-ignore argument.type
            ->all());
        $categoryTypes = Category::query()->pluck('type', 'id');
        $threshold = (float) config('bank_import.confidence_threshold');
        $stats = ['sent' => 0, 'auto' => 0, 'to_review' => 0, 'unknown' => 0];

        foreach (array_chunk($toSend, max(1, (int) config('bank_import.batch_size'))) as $chunk) {
            // Random single-use ids, shuffled: the model cannot link merchants across requests or to the import order.
            shuffle($chunk);
            $keysById = [];
            $inputs = [];

            foreach ($chunk as $item) {
                do {
                    $id = Str::lower(Str::random(6));
                } while (isset($keysById[$id]));

                $keysById[$id] = $item['merchant_key'];
                $inputs[] = new MerchantInput($id, $item['name'], $item['direction'], $item['bank_category']);
            }

            $suggestions = collect($this->categorizer->categorize($inputs, $categories))->keyBy('id');
            $stats['sent'] += count($inputs);

            foreach ($keysById as $id => $merchantKey) {
                /** @var MerchantSuggestion|null $suggestion */
                $suggestion = $suggestions->get($id);
                $transactions = $import->transactions()->where('merchant_key', $merchantKey)->where('status', TransactionStatus::ToReview);
                $direction = $chunk[array_search($merchantKey, array_column($chunk, 'merchant_key'), true)]['direction'];
                $type = $suggestion?->categoryId !== null ? $categoryTypes->get($suggestion->categoryId) : null;
                $fits = $type === CategoryType::Expense || ($type === CategoryType::Income && $direction === 'entrata');

                if ($suggestion === null || $suggestion->categoryId === null || ! $fits) {
                    // Remember it was asked, keep any bank suggestion.
                    $transactions->update(['categorization_source' => CategorizationSource::Llm, 'confidence' => 0]);
                    $stats['unknown']++;

                    continue;
                }

                $confident = $suggestion->confidence >= $threshold && ! $suggestion->ambiguous;
                $transactions->update([
                    'category_id' => $suggestion->categoryId,
                    'categorization_source' => CategorizationSource::Llm,
                    'confidence' => round($suggestion->confidence, 2),
                    'status' => $confident ? TransactionStatus::Auto : TransactionStatus::ToReview,
                ]);
                $stats[$confident ? 'auto' : 'to_review']++;
            }
        }

        $import->update([
            'llm_stats' => [...$stats, 'provider' => $this->categorizer->name()],
            'llm_ran_at' => now(),
        ]);
    }

    public function categorizer(): MerchantCategorizer
    {
        return $this->categorizer;
    }
}
