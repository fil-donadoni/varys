<?php

namespace App\Services;

use App\Models\BudgetEntry;
use Illuminate\Support\Collection;

/**
 * Sums the invoiced part of a set of budget entries.
 *
 * An entry split into items is counted through its flagged items only;
 * an entry without items counts as a whole when it is flagged itself.
 */
class InvoicedBudgetTotal
{
    /**
     * @param  Collection<int, BudgetEntry>  $entries  with the `items` relation loaded
     */
    public function of(Collection $entries): float
    {
        $total = 0.0;

        foreach ($entries as $entry) {
            if ($entry->items->isNotEmpty()) {
                $total += (float) $entry->items->where('is_invoiced', true)->sum('amount');

                continue;
            }

            if ($entry->is_invoiced) {
                $total += (float) $entry->amount;
            }
        }

        return round($total, 2);
    }
}
