<?php

namespace App\Http\Controllers\App;

use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Models\ActualItem;
use App\Models\BankTransaction;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Services\ActualVarianceReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActualEntryController extends Controller
{
    public function index(Request $request, ActualVarianceReport $report): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $month = (int) $request->query('month', (string) now()->month);
        $tab = $request->query('tab') === 'year' ? 'year' : 'month';

        $categories = Category::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'type', 'color', 'sort_order']);

        $budgets = BudgetEntry::query()
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('amount', 'category_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();

        return Inertia::render('actual/index', [
            'tab' => $tab,
            'year' => $year,
            'month' => $month,
            'expandedCategoryId' => $request->filled('category') ? $request->integer('category') : null,
            'categories' => $categories,
            'budgets' => (object) $budgets,
            'lines' => (object) $this->monthLines($categories, $year, $month),
            'report' => $report->build($year, CarbonImmutable::now()),
        ]);
    }

    /**
     * Every line making up each category actual in the month: confirmed movements and manual items.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, list<array{key: string, kind: string, id: int, date: string, description: string, amount: float, bank_amount: float|null, kind_label: string|null}>>
     */
    private function monthLines(Collection $categories, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $range = [$start->toDateString(), $start->endOfMonth()->toDateString()];
        $types = $categories->pluck('type', 'id');
        $lines = [];

        $movements = BankTransaction::query()
            ->where('status', TransactionStatus::Confirmed)
            ->whereNotNull('category_id')
            ->whereBetween('accounting_date', $range)
            ->get();

        foreach ($movements as $movement) {
            $categoryId = (int) $movement->category_id;
            $bankAmount = (float) $movement->amount;
            $lines[$categoryId][] = [
                'key' => "bank-{$movement->id}",
                'kind' => 'bank',
                'id' => $movement->id,
                'date' => $movement->accounting_date->toDateString(),
                'description' => $movement->merchant_label,
                'amount' => $types->get($categoryId) === CategoryType::Expense ? -$bankAmount : $bankAmount,
                'bank_amount' => $bankAmount,
                'kind_label' => $movement->kind->label(),
            ];
        }

        foreach (ActualItem::query()->whereBetween('date', $range)->get() as $item) {
            $lines[$item->category_id][] = [
                'key' => "manual-{$item->id}",
                'kind' => 'manual',
                'id' => $item->id,
                'date' => $item->date->toDateString(),
                'description' => $item->description,
                'amount' => (float) $item->amount,
                'bank_amount' => null,
                'kind_label' => null,
            ];
        }

        foreach ($lines as &$categoryLines) {
            usort($categoryLines, fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
        }
        unset($categoryLines);

        return $lines;
    }
}
