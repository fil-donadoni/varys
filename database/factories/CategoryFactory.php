<?php

namespace Database\Factories;

use App\Enums\CategoryType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Category>
 */
class CategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'type' => fake()->randomElement(CategoryType::cases()),
            'color' => fake()->hexColor(),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_invoiced' => false,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (): array => ['type' => CategoryType::Income]);
    }

    public function expense(): static
    {
        return $this->state(fn (): array => ['type' => CategoryType::Expense]);
    }

    public function invoiced(): static
    {
        return $this->state(fn (): array => ['is_invoiced' => true]);
    }
}
