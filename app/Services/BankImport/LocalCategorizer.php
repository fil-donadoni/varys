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
 * merchant memory → similar merchant memory → keyword rules → bank category (as a suggestion only).
 */
class LocalCategorizer
{
    public function categorize(BankImport $import): void
    {
        $exactRules = MerchantRule::query()
            ->where('match_type', MerchantMatchType::Exact)
            ->get()
            ->keyBy(fn (MerchantRule $rule): string => MerchantNormalizer::canonical($rule->pattern));
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
            $key = MerchantNormalizer::canonical($transaction->merchant_key);
            $rule = $exactRules->get($key);

            if ($rule !== null && $this->applyRule($transaction, $rule, $categoryTypes)) {
                continue;
            }

            if ($this->applySimilarRule($transaction, $key, $exactRules, $categoryTypes)) {
                continue;
            }

            $rule = $keywordRules->first(fn (MerchantRule $r): bool => str_contains($key, mb_strtoupper($r->pattern)));

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
     * The same merchant is often written differently by two banks, e.g. "FARMACIA DELLA BASILI" (ING)
     * and "FARMACIA DELLA BASILI MAGENTA" (Intesa): a remembered merchant whose words are the beginning
     * of the other one matches. With two or more shared words it is applied, with one it is only proposed.
     *
     * @param  Collection<string, MerchantRule>  $exactRules  Keyed by canonical pattern.
     * @param  Collection<int, CategoryType>  $categoryTypes
     */
    private function applySimilarRule(BankTransaction $transaction, string $key, Collection $exactRules, Collection $categoryTypes): bool
    {
        $best = null;
        $bestWords = 0;

        foreach ($exactRules as $pattern => $rule) {
            $pattern = (string) $pattern;
            [$short, $long] = mb_strlen($pattern) <= mb_strlen($key) ? [$pattern, $key] : [$key, $pattern];

            if (mb_strlen($short) < 5 || ! str_starts_with($long.' ', $short.' ')) {
                continue;
            }

            $words = count(explode(' ', $short));

            if ($words > $bestWords) {
                $best = $rule;
                $bestWords = $words;
            }
        }

        if ($best === null || $best->exclude || $best->category_id === null || ! $this->fits($transaction, $best->category_id, $categoryTypes)) {
            return false;
        }

        $transaction->update([
            'category_id' => $best->category_id,
            'categorization_source' => CategorizationSource::SimilarMemory,
            'status' => $bestWords >= 2 && ! $best->always_ask ? TransactionStatus::Auto : TransactionStatus::ToReview,
        ]);

        return true;
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
