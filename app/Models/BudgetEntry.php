<?php

namespace App\Models;

use App\Models\Traits\Filterable;
use Database\Factories\BudgetEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetEntry extends Model
{
    /** @use HasFactory<BudgetEntryFactory> */
    use Filterable, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return HasMany<BudgetEntryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(BudgetEntryItem::class)->orderBy('sort_order')->orderBy('id');
    }
}
