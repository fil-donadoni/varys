<?php

namespace App\Services\BankImport;

use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\BankTransaction;
use Carbon\CarbonImmutable;

/**
 * Keeps actual_entries.amount = manual_amount + imported_amount,
 * where imported_amount is the sum of confirmed bank transactions for that category and month.
 */
class ActualEntryRecalculator
{
    public function recalculate(int $categoryId, int $year, int $month): void
    {
        $start = CarbonImmutable::create($year, $month, 1);

        $imported = (float) BankTransaction::query()
            ->where('status', TransactionStatus::Confirmed)
            ->where('category_id', $categoryId)
            ->whereBetween('operation_date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->sum('amount');

        $entry = ActualEntry::query()
            ->where('category_id', $categoryId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $manual = (float) ($entry->manual_amount ?? 0);

        if (round($manual, 2) === 0.0 && round($imported, 2) === 0.0) {
            $entry?->delete();

            return;
        }

        $entry ??= new ActualEntry(['category_id' => $categoryId, 'year' => $year, 'month' => $month, 'manual_amount' => 0]);
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

            $date = $transaction->operation_date;
            $targets["{$transaction->category_id}-{$date->year}-{$date->month}"] = [$transaction->category_id, $date->year, $date->month];
        }

        foreach ($targets as [$categoryId, $year, $month]) {
            $this->recalculate($categoryId, $year, $month);
        }
    }
}
