<?php

namespace Database\Seeders;

use App\Enums\CategoryType;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $incomeCategories = [
            ['name' => 'Stipendio', 'type' => CategoryType::Income, 'color' => '#22c55e', 'sort_order' => 1, 'is_invoiced' => true],
            ['name' => 'Freelance', 'type' => CategoryType::Income, 'color' => '#3b82f6', 'sort_order' => 2, 'is_invoiced' => true],
            ['name' => 'Investimenti', 'type' => CategoryType::Income, 'color' => '#8b5cf6', 'sort_order' => 3, 'is_invoiced' => false],
            ['name' => 'Altro (entrate)', 'type' => CategoryType::Income, 'color' => '#6b7280', 'sort_order' => 4, 'is_invoiced' => false],
        ];

        $expenseCategories = [
            ['name' => 'Mutuo casa', 'type' => CategoryType::Expense, 'color' => '#ef4444', 'sort_order' => 1],
            ['name' => 'Finanziamento auto', 'type' => CategoryType::Expense, 'color' => '#f97316', 'sort_order' => 2],
            ['name' => 'Cloud e Hosting', 'type' => CategoryType::Expense, 'color' => '#eab308', 'sort_order' => 3],
            ['name' => 'Assicurazione', 'type' => CategoryType::Expense, 'color' => '#14b8a6', 'sort_order' => 4],
            ['name' => 'Spesa casa', 'type' => CategoryType::Expense, 'color' => '#ec4899', 'sort_order' => 5],
            ['name' => 'Pranzi/cene', 'type' => CategoryType::Expense, 'color' => '#a855f7', 'sort_order' => 6],
            ['name' => 'Telefono', 'type' => CategoryType::Expense, 'color' => '#06b6d4', 'sort_order' => 7],
            ['name' => 'TV e Internet', 'type' => CategoryType::Expense, 'color' => '#84cc16', 'sort_order' => 8],
            ['name' => 'Formazione', 'type' => CategoryType::Expense, 'color' => '#6b7280', 'sort_order' => 9],
            ['name' => 'Magic', 'type' => CategoryType::Expense, 'color' => '#8b5cf6', 'sort_order' => 10],
            ['name' => 'Bollo / assicurazione auto', 'type' => CategoryType::Expense, 'color' => '#3b82f6', 'sort_order' => 11],
            ['name' => 'Benzina', 'type' => CategoryType::Expense, 'color' => '#f43f5e', 'sort_order' => 12],
            ['name' => 'Telepass', 'type' => CategoryType::Expense, 'color' => '#10b981', 'sort_order' => 13],
            ['name' => 'Lavori casa e giardino', 'type' => CategoryType::Expense, 'color' => '#d97706', 'sort_order' => 14],
            ['name' => 'Bollette', 'type' => CategoryType::Expense, 'color' => '#0ea5e9', 'sort_order' => 15],
            ['name' => 'Fondo pensione', 'type' => CategoryType::Expense, 'color' => '#7c3aed', 'sort_order' => 16],
            ['name' => 'Tasse', 'type' => CategoryType::Expense, 'color' => '#dc2626', 'sort_order' => 17],
            ['name' => 'Nadia', 'type' => CategoryType::Expense, 'color' => '#e879f9', 'sort_order' => 18],
            ['name' => 'Vacanze', 'type' => CategoryType::Expense, 'color' => '#fb923c', 'sort_order' => 19],
            ['name' => 'Regali', 'type' => CategoryType::Expense, 'color' => '#c084fc', 'sort_order' => 20],
            ['name' => 'Altro', 'type' => CategoryType::Expense, 'color' => '#94a3b8', 'sort_order' => 21],
        ];

        $categories = collect(array_merge($incomeCategories, $expenseCategories))
            ->map(fn (array $data): Category => Category::create($data));

        $year = now()->year;
        $currentMonth = now()->month;

        foreach ($categories as $category) {
            $baseAmount = match ($category->name) {
                'Stipendio' => 2800.00,
                'Freelance' => 500.00,
                'Investimenti' => 150.00,
                'Altro (entrate)' => 100.00,
                'Mutuo casa' => 900.00,
                'Finanziamento auto' => 350.00,
                'Cloud e Hosting' => 50.00,
                'Assicurazione' => 120.00,
                'Spesa casa' => 450.00,
                'Pranzi/cene' => 200.00,
                'Telefono' => 30.00,
                'TV e Internet' => 60.00,
                'Formazione' => 80.00,
                'Magic' => 100.00,
                'Bollo / assicurazione auto' => 150.00,
                'Benzina' => 120.00,
                'Telepass' => 40.00,
                'Lavori casa e giardino' => 150.00,
                'Bollette' => 200.00,
                'Fondo pensione' => 200.00,
                'Tasse' => 300.00,
                'Nadia' => 100.00,
                'Vacanze' => 250.00,
                'Regali' => 80.00,
                'Altro' => 100.00,
                default => 100.00,
            };

            for ($month = 1; $month <= 12; $month++) {
                $variation = $baseAmount * fake()->randomFloat(2, -0.1, 0.1);

                BudgetEntry::create([
                    'category_id' => $category->id,
                    'year' => $year,
                    'month' => $month,
                    'amount' => round($baseAmount + $variation, 2),
                ]);
            }

            for ($month = 1; $month < $currentMonth; $month++) {
                $actualVariation = $baseAmount * fake()->randomFloat(2, -0.2, 0.2);

                ActualEntry::create([
                    'category_id' => $category->id,
                    'year' => $year,
                    'month' => $month,
                    'amount' => round($baseAmount + $actualVariation, 2),
                ]);
            }
        }
    }
}
