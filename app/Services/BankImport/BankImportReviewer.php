<?php

namespace App\Services\BankImport;

use App\Enums\BankImportStatus;
use App\Enums\CategorizationSource;
use App\Enums\MerchantMatchType;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\MerchantRule;
use Illuminate\Support\Facades\DB;

/**
 * User decisions during review, and the final confirmation that feeds actual entries and merchant memory.
 */
class BankImportReviewer
{
    public function __construct(private readonly ActualEntryRecalculator $recalculator) {}

    /**
     * Assigns a category (or excludes) one transaction, optionally every pending one of the same merchant in the import.
     */
    public function assign(BankTransaction $transaction, ?int $categoryId, bool $exclude, bool $applyToMerchant): void
    {
        $query = $applyToMerchant
            ? BankTransaction::query()
                ->where('bank_import_id', $transaction->bank_import_id)
                ->where('merchant_key', $transaction->merchant_key)
                ->whereIn('status', [TransactionStatus::ToReview, TransactionStatus::Auto, TransactionStatus::Excluded])
            : BankTransaction::query()->whereKey($transaction->id);

        $query->update([
            'category_id' => $exclude ? null : $categoryId,
            'categorization_source' => CategorizationSource::Manual,
            'confidence' => null,
            'status' => match (true) {
                $exclude => TransactionStatus::Excluded,
                $categoryId !== null => TransactionStatus::Auto,
                default => TransactionStatus::ToReview,
            },
        ]);
    }

    public function complete(BankImport $import): void
    {
        DB::transaction(function () use ($import): void {
            $transactions = $import->transactions()
                ->whereIn('status', [TransactionStatus::Auto, TransactionStatus::Excluded])
                ->get();

            foreach ($transactions->groupBy('merchant_key') as $merchantKey => $group) {
                $this->remember((string) $merchantKey, $group->first());
            }

            $import->transactions()->where('status', TransactionStatus::Auto)->update(['status' => TransactionStatus::Confirmed]);
            $import->update(['status' => BankImportStatus::Completed, 'completed_at' => now()]);

            $this->recalculator->recalculateFor($import->transactions()->where('status', TransactionStatus::Confirmed)->get());
        });
    }

    public function delete(BankImport $import): void
    {
        DB::transaction(function () use ($import): void {
            $affected = $import->transactions()->where('status', TransactionStatus::Confirmed)->get();

            $import->delete();

            $this->recalculator->recalculateFor($affected);
        });
    }

    /**
     * Stores the decision for a merchant so the next import categorizes it automatically.
     * Keyword matches are not copied into exact rules: the keyword already covers them.
     */
    private function remember(string $merchantKey, ?BankTransaction $transaction): void
    {
        if ($transaction === null || $transaction->categorization_source === CategorizationSource::Keyword) {
            return;
        }

        $rule = MerchantRule::query()->firstOrNew(['match_type' => MerchantMatchType::Exact, 'pattern' => $merchantKey]);
        $exclude = $transaction->status === TransactionStatus::Excluded;

        $rule->fill([
            'category_id' => $exclude ? null : $transaction->category_id,
            'exclude' => $exclude,
        ]);
        $rule->times_confirmed++;
        $rule->save();
    }
}
