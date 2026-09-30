<?php

namespace Database\Factories;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use App\Models\BankImport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankImport>
 */
class BankImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bank' => fake()->randomElement(Bank::cases()),
            'original_filename' => 'movimenti.xlsx',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'rows_total' => 0,
            'rows_imported' => 0,
            'rows_duplicates' => 0,
            'status' => BankImportStatus::Review,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => ['status' => BankImportStatus::Completed, 'completed_at' => now()]);
    }
}
