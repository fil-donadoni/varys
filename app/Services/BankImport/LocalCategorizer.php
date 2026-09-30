<?php

namespace App\Services\BankImport;

use App\Enums\CategorizationSource;
use App\Enums\CategoryType;
use App\Enums\MerchantMatchType;
use App\Enums\TransactionStatus;
use App\Models\BankCategoryMapping;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use Illuminate\Support\Collection;

/**
 * Categorizes transactions without leaving the machine:
 * merchant memory → keyword rules → bank category (as a suggestion only).
 */
class LocalCategorizer
{
    public function categorize(BankImport $import): void
    {
        $exactRules = MerchantRule::query()->where('match_type', MerchantMatchType::Exact)->get()->keyBy('pattern');
        $keywordRules = MerchantRule::query()
            ->where('match_type', MerchantMatchType::Contains)
            ->get()
            ->sortByDesc(fn (MerchantRule $rule): int => mb_strlen($rule->pattern));
        $bankMappings = BankCategoryMapping::query()
            ->where('bank', $import->bank)
            ->whereNotNull('category_id')
            ->pluck('category_id', 'bank_category');
        /** @var Collection<int, CategoryType> $categoryTypes */
        $categoryTypes = Category::query()->pluck('type', 'id');

        $transactions = $import->transactions()->where('status', TransactionStatus::ToReview)->whereNull('categorization_source')->get();

        foreach ($transactions as $transaction) {
            $rule = $exactRules->get($transaction->merchant_key)
                ?? $keywordRules->first(fn (MerchantRule $r): bool => str_contains($transaction->merchant_key, mb_strtoupper($r->pattern)));

            if ($rule !== null && $this->applyRule($transaction, $rule, $categoryTypes)) {
                continue;
            }

            $mapped = $transaction->bank_category !== null ? $bankMappings->get($transaction->bank_category) : null;

            if ($mapped !== null && $this->fits($transaction, (int) $mapped, $categoryTypes)) {
                $transaction->update([
                    'category_id' => $mapped,
                    'categorization_source' => CategorizationSource::Bank,
                ]);
            }
        }
    }

    /**
     * @param  Collection<int, CategoryType>  $categoryTypes
     */
    private function applyRule(BankTransaction $transaction, MerchantRule $rule, Collection $categoryTypes): bool
    {
        $source = $rule->match_type === MerchantMatchType::Exact ? CategorizationSource::Memory : CategorizationSource::Keyword;

        if ($rule->exclude) {
            $transaction->update(['status' => TransactionStatus::Excluded, 'categorization_source' => $source, 'category_id' => null]);

            return true;
        }

        if ($rule->category_id === null || ! $this->fits($transaction, $rule->category_id, $categoryTypes)) {
            return false;
        }

        $transaction->update([
            'category_id' => $rule->category_id,
            'categorization_source' => $source,
            'status' => $rule->always_ask ? TransactionStatus::ToReview : TransactionStatus::Auto,
        ]);

        return true;
    }

    /**
     * Income categories only take money in; expense categories take expenses and refunds.
     *
     * @param  Collection<int, CategoryType>  $categoryTypes
     */
    public function fits(BankTransaction $transaction, int $categoryId, Collection $categoryTypes): bool
    {
        $type = $categoryTypes->get($categoryId);

        return $type === CategoryType::Expense || ($type === CategoryType::Income && (float) $transaction->amount > 0);
    }
}
