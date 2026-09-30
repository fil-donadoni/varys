<?php

namespace Database\Factories;

use App\Models\ActualEntry;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActualEntry>
 */
class ActualEntryFactory extends Factory
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
            'description' => fake()->optional()->sentence(),
            'notes' => fake()->optional()->sentence(),
        ];
    }

    public function configure(): static
    {
        // Entries created without explicit split are fully manual.
        return $this->afterMaking(function (ActualEntry $entry): void {
            if ($entry->getAttribute('manual_amount') === null && $entry->getAttribute('imported_amount') === null) {
                $entry->manual_amount = $entry->amount;
                $entry->imported_amount = '0.00';
            }
        });
    }
}
