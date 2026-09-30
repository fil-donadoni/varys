<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Converts the old single manual amount of each actual entry into an actual item.
 * Used by the migration and when restoring backups made before actual items existed.
 */
class ActualItemBackfill
{
    public function fromManualAmounts(): int
    {
        $entries = DB::table('actual_entries')->where('manual_amount', '<>', 0)->orderBy('id')->get();
        $now = now();

        foreach ($entries as $entry) {
            DB::table('actual_items')->insert([
                'category_id' => $entry->category_id,
                'date' => sprintf('%04d-%02d-01', $entry->year, $entry->month),
                'description' => ($entry->description ?? '') !== '' ? $entry->description : 'Voce manuale',
                'amount' => $entry->manual_amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $entries->count();
    }
}
