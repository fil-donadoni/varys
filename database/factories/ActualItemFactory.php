<?php

namespace Database\Factories;

use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActualItem>
 */
class ActualItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'date' => now()->startOfMonth()->toDateString(),
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 5, 300),
        ];
    }
}
