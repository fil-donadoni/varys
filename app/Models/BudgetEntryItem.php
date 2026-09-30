<?php

namespace App\Models;

use Database\Factories\BudgetEntryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetEntryItem extends Model
{
    /** @use HasFactory<BudgetEntryItemFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BudgetEntry, $this>
     */
    public function budgetEntry(): BelongsTo
    {
        return $this->belongsTo(BudgetEntry::class);
    }
}
