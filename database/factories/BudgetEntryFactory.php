<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BudgetEntry>
 */
class BudgetEntryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'year' => now()->year,
            'month' => fake()->numberBetween(1, 12),
            'amount' => fake()->randomFloat(2, 100, 5000),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
