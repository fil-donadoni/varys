<?php

namespace App\Models;

use App\Enums\CategoryType;
use App\Models\Traits\Filterable;
use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use Filterable, HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CategoryType::class,
            'is_invoiced' => 'boolean',
        ];
    }

    /**
     * @return HasMany<BudgetEntry, $this>
     */
    public function budgetEntries(): HasMany
    {
        return $this->hasMany(BudgetEntry::class);
    }

    /**
     * @return HasMany<ActualEntry, $this>
     */
    public function actualEntries(): HasMany
    {
        return $this->hasMany(ActualEntry::class);
    }
}
