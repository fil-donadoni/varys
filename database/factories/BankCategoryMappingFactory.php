<?php

namespace Database\Factories;

use App\Enums\Bank;
use App\Models\BankCategoryMapping;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankCategoryMapping>
 */
class BankCategoryMappingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank' => Bank::Intesa,
            'bank_category' => fake()->unique()->words(2, true),
            'category_id' => Category::factory()->expense(),
        ];
    }
}
