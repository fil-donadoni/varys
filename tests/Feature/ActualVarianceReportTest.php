<?php

use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Services\ActualVarianceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, float>  $budget  month => amount
 * @param  array<int, float>  $actual  month => amount
 */
function seedMonths(Category $category, int $year, array $budget, array $actual): void
{
    foreach ($budget as $month => $amount) {
        BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => $year, 'month' => $month, 'amount' => $amount]);
    }
    foreach ($actual as $month => $amount) {
        ActualEntry::factory()->create(['category_id' => $category->id, 'year' => $year, 'month' => $month, 'amount' => $amount]);
    }
}

/**
 * @param  array<string, mixed>  $report
 * @return array<string, mixed>
 */
function rowFor(array $report, Category $category): array
{
    return collect($report['rows'])->firstWhere('category_id', $category->id);
}

test('current year averages only closed months and finds the peak', function (): void {
    $dining = Category::factory()->expense()->create();
    seedMonths($dining, 2026, array_fill(1, 12, 300.0), [1 => 300, 2 => 350, 8 => 580, 9 => 900]);

    $report = app(ActualVarianceReport::class)->build(2026, CarbonImmutable::parse('2026-09-30'));
    $row = rowFor($report, $dining);

    expect($report['closed_months'])->toBe(8)
        ->and($row['avg_budget'])->toBe(300.0)
        ->and($row['avg_actual'])->toBe(153.75)
        ->and($row['variance'])->toBe(-146.25)
        ->and($row['variance_pct'])->toBe(-0.4875)
        ->and($row['peak'])->toBe(['month' => 8, 'actual' => 580.0])
        ->and($row['months'][8]['status'])->toBe('current')
        ->and($row['months'][9]['status'])->toBe('future')
        ->and($row['months'][7])->toBe(['month' => 8, 'budget' => 300.0, 'actual' => 580.0, 'status' => 'closed']);
});

test('past years use all twelve months, future years have no averages', function (): void {
    $dining = Category::factory()->expense()->create();
    seedMonths($dining, 2025, [1 => 100], [1 => 1200]);
    seedMonths($dining, 2027, [1 => 100], []);

    $past = rowFor(app(ActualVarianceReport::class)->build(2025, CarbonImmutable::parse('2026-09-30')), $dining);
    $future = app(ActualVarianceReport::class)->build(2027, CarbonImmutable::parse('2026-09-30'));

    expect($past['avg_actual'])->toBe(100.0)
        ->and($past['avg_budget'])->toBe(8.33)
        ->and($future['closed_months'])->toBe(0)
        ->and(rowFor($future, $dining)['avg_actual'])->toBeNull()
        ->and(rowFor($future, $dining)['variance_pct'])->toBeNull()
        ->and(rowFor($future, $dining)['peak'])->toBeNull();
});

test('zero budget gives no percentage and totals combine categories', function (): void {
    $gifts = Category::factory()->expense()->create();
    $salary = Category::factory()->income()->create();
    seedMonths($gifts, 2025, [], [3 => 120]);
    seedMonths($salary, 2025, array_fill(1, 12, 2000.0), array_fill(1, 12, 2100.0));

    $report = app(ActualVarianceReport::class)->build(2025, CarbonImmutable::parse('2026-09-30'));

    expect(rowFor($report, $gifts)['variance'])->toBe(10.0)
        ->and(rowFor($report, $gifts)['variance_pct'])->toBeNull()
        ->and($report['totals']['income']['avg_actual'])->toBe(2100.0)
        ->and($report['totals']['expense']['avg_actual'])->toBe(10.0)
        ->and($report['totals']['net']['avg_actual'])->toBe(2090.0)
        ->and($report['totals']['net']['avg_budget'])->toBe(2000.0)
        ->and($report['totals']['net']['peak'])->toBeNull();
});
