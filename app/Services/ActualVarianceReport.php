<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use Carbon\CarbonImmutable;

/**
 * Budget vs actual per category across a year. Averages cover only closed months,
 * so a half-spent current month doesn't pull the average down.
 *
 * @phpstan-type MonthCell array{month: int, budget: float, actual: float, status: 'closed'|'current'|'future'}
 * @phpstan-type Row array{category_id: int|null, type: string, months: list<MonthCell>, avg_budget: float|null, avg_actual: float|null, variance: float|null, variance_pct: float|null, peak: array{month: int, actual: float}|null}
 */
class ActualVarianceReport
{
    /**
     * @return array{closed_months: int, rows: list<Row>, totals: array{income: Row, expense: Row, net: Row}}
     */
    public function build(int $year, CarbonImmutable $today): array
    {
        $closedMonths = match (true) {
            $year < $today->year => 12,
            $year === $today->year => $today->month - 1,
            default => 0,
        };

        $categories = Category::query()->orderBy('type')->orderBy('sort_order')->get();
        $budget = $this->amountsByCategoryAndMonth(BudgetEntry::query()->where('year', $year)->get(['category_id', 'month', 'amount']));
        $actual = $this->amountsByCategoryAndMonth(ActualEntry::query()->where('year', $year)->get(['category_id', 'month', 'amount']));

        $empty = array_fill(1, 12, 0.0);
        $sums = [
            CategoryType::Income->value => ['budget' => $empty, 'actual' => $empty],
            CategoryType::Expense->value => ['budget' => $empty, 'actual' => $empty],
        ];

        $rows = [];
        foreach ($categories as $category) {
            /** @var CategoryType $type */
            $type = $category->type;
            $categoryBudget = $budget[$category->id] ?? [];
            $categoryActual = $actual[$category->id] ?? [];

            for ($month = 1; $month <= 12; $month++) {
                $sums[$type->value]['budget'][$month] += $categoryBudget[$month] ?? 0.0;
                $sums[$type->value]['actual'][$month] += $categoryActual[$month] ?? 0.0;
            }

            $rows[] = $this->row($category->id, $type->value, $categoryBudget, $categoryActual, $year, $today, $closedMonths, true);
        }

        $income = $sums[CategoryType::Income->value];
        $expense = $sums[CategoryType::Expense->value];
        $netBudget = [];
        $netActual = [];
        for ($month = 1; $month <= 12; $month++) {
            $netBudget[$month] = $income['budget'][$month] - $expense['budget'][$month];
            $netActual[$month] = $income['actual'][$month] - $expense['actual'][$month];
        }

        return [
            'closed_months' => $closedMonths,
            'rows' => $rows,
            'totals' => [
                'income' => $this->row(null, 'income', $income['budget'], $income['actual'], $year, $today, $closedMonths, true),
                'expense' => $this->row(null, 'expense', $expense['budget'], $expense['actual'], $year, $today, $closedMonths, true),
                // Net: higher is better, like income; a peak means nothing here.
                'net' => $this->row(null, 'income', $netBudget, $netActual, $year, $today, $closedMonths, false),
            ],
        ];
    }

    /**
     * @param  iterable<BudgetEntry|ActualEntry>  $entries
     * @return array<int, array<int, float>>
     */
    private function amountsByCategoryAndMonth(iterable $entries): array
    {
        $amounts = [];
        foreach ($entries as $entry) {
            $amounts[$entry->category_id][$entry->month] = ($amounts[$entry->category_id][$entry->month] ?? 0.0) + (float) $entry->amount;
        }

        return $amounts;
    }

    /**
     * @param  array<int, float>  $budget
     * @param  array<int, float>  $actual
     * @return Row
     */
    private function row(?int $categoryId, string $type, array $budget, array $actual, int $year, CarbonImmutable $today, int $closedMonths, bool $withPeak): array
    {
        $months = [];
        $peak = null;
        $budgetSum = 0.0;
        $actualSum = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $monthBudget = round($budget[$month] ?? 0.0, 2);
            $monthActual = round($actual[$month] ?? 0.0, 2);
            $status = match (true) {
                $month <= $closedMonths => 'closed',
                $year === $today->year && $month === $today->month => 'current',
                default => 'future',
            };

            if ($status === 'closed') {
                $budgetSum += $monthBudget;
                $actualSum += $monthActual;
                if ($withPeak && $monthActual > 0 && ($peak === null || $monthActual > $peak['actual'])) {
                    $peak = ['month' => $month, 'actual' => $monthActual];
                }
            }

            $months[] = ['month' => $month, 'budget' => $monthBudget, 'actual' => $monthActual, 'status' => $status];
        }

        $avgBudget = $closedMonths > 0 ? round($budgetSum / $closedMonths, 2) : null;
        $avgActual = $closedMonths > 0 ? round($actualSum / $closedMonths, 2) : null;
        $variance = $avgBudget !== null && $avgActual !== null ? round($avgActual - $avgBudget, 2) : null;

        return [
            'category_id' => $categoryId,
            'type' => $type,
            'months' => $months,
            'avg_budget' => $avgBudget,
            'avg_actual' => $avgActual,
            'variance' => $variance,
            'variance_pct' => $variance !== null && $avgBudget !== 0.0 ? round($variance / abs($avgBudget), 4) : null,
            'peak' => $peak,
        ];
    }
}
