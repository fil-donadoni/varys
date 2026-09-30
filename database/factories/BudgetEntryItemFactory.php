<?php

namespace Database\Factories;

use App\Models\BudgetEntry;
use App\Models\BudgetEntryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetEntryItem>
 */
class BudgetEntryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_entry_id' => BudgetEntry::factory(),
            'description' => fake()->words(3, true),
            'amount' => fake()->randomFloat(2, 50, 2000),
            'sort_order' => 0,
        ];
    }
}
