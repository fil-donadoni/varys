<?php

namespace App\Services\BankImport;

use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\ActualItem;
use App\Models\BankTransaction;
use App\Models\Category;
use Carbon\CarbonImmutable;

/**
 * Keeps actual_entries in sync: manual_amount = sum of actual items, imported_amount = sum of confirmed
 * bank transactions for that category and accounting month, amount = both.
 * Bank amounts are signed (negative = money out): expense categories count outflows as positive.
 */
class ActualEntryRecalculator
{
    public function recalculate(int $categoryId, int $year, int $month): void
    {
        $start = CarbonImmutable::create($year, $month, 1);

        $bankTotal = (float) BankTransaction::query()
            ->where('status', TransactionStatus::Confirmed)
            ->where('category_id', $categoryId)
            ->whereBetween('accounting_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->sum('amount');

        $imported = Category::query()->whereKey($categoryId)->value('type') === CategoryType::Expense ? -$bankTotal : $bankTotal;

        $entry = ActualEntry::query()
            ->where('category_id', $categoryId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $manual = (float) ActualItem::query()
            ->where('category_id', $categoryId)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->sum('amount');

        if (round($manual, 2) === 0.0 && round($imported, 2) === 0.0) {
            $entry?->delete();

            return;
        }

        $entry ??= new ActualEntry(['category_id' => $categoryId, 'year' => $year, 'month' => $month, 'manual_amount' => 0]);
        $entry->manual_amount = round($manual, 2);
        $entry->imported_amount = round($imported, 2);
        $entry->amount = round($manual + $imported, 2);
        $entry->save();
    }

    /**
     * @param  iterable<BankTransaction>  $transactions
     */
    public function recalculateFor(iterable $transactions): void
    {
        $targets = [];

        foreach ($transactions as $transaction) {
            if ($transaction->category_id === null) {
                continue;
            }

            $date = $transaction->accounting_date;
            $targets["{$transaction->category_id}-{$date->year}-{$date->month}"] = [$transaction->category_id, $date->year, $date->month];
        }

        foreach ($targets as [$categoryId, $year, $month]) {
            $this->recalculate($categoryId, $year, $month);
        }
    }
}
