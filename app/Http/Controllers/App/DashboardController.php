<?php

namespace App\Http\Controllers\App;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Setting;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $categories = Category::query()->orderBy('sort_order')->get();

        /** @var array<int, CategoryType> $categoryTypeMap */
        $categoryTypeMap = $categories->pluck('type', 'id')->all();

        $budgetEntries = BudgetEntry::query()
            ->where('year', $year)
            ->get();

        $actualEntries = ActualEntry::query()
            ->where('year', $year)
            ->get();

        $monthlyData = [];
        for ($month = 1; $month <= 12; $month++) {
            $budgetIncome = 0.0;
            $budgetExpense = 0.0;
            $actualIncome = 0.0;
            $actualExpense = 0.0;

            foreach ($budgetEntries as $entry) {
                if ($entry->month !== $month) {
                    continue;
                }

                $type = $categoryTypeMap[$entry->category_id] ?? null;
                if ($type === CategoryType::Income) {
                    $budgetIncome += (float) $entry->amount;
                } elseif ($type === CategoryType::Expense) {
                    $budgetExpense += (float) $entry->amount;
                }
            }

            foreach ($actualEntries as $entry) {
                if ($entry->month !== $month) {
                    continue;
                }

                $type = $categoryTypeMap[$entry->category_id] ?? null;
                if ($type === CategoryType::Income) {
                    $actualIncome += (float) $entry->amount;
                } elseif ($type === CategoryType::Expense) {
                    $actualExpense += (float) $entry->amount;
                }
            }

            $hasActual = $actualEntries->where('month', $month)->contains(fn (ActualEntry $e): bool => (float) $e->amount > 0);

            $monthlyData[] = [
                'month' => $month,
                'budget_income' => round($budgetIncome, 2),
                'budget_expense' => round($budgetExpense, 2),
                'budget_balance' => round($budgetIncome - $budgetExpense, 2),
                'actual_income' => round($actualIncome, 2),
                'actual_expense' => round($actualExpense, 2),
                'actual_balance' => round($actualIncome - $actualExpense, 2),
                'has_actual' => $hasActual,
            ];
        }

        $categoryData = [];
        foreach ($categories as $category) {
            /** @var CategoryType $type */
            $type = $category->type;

            $categoryData[] = [
                'id' => $category->id,
                'name' => $category->name,
                'type' => $type->value,
                'color' => $category->color,
                'budget_total' => round((float) $budgetEntries->where('category_id', $category->id)->sum('amount'), 2),
                'actual_total' => round((float) $actualEntries->where('category_id', $category->id)->sum('amount'), 2),
            ];
        }

        $currentMonth = now()->month;
        $missingCount = 0;
        foreach ($categories as $category) {
            for ($month = 1; $month < $currentMonth; $month++) {
                $hasActual = $actualEntries
                    ->where('category_id', $category->id)
                    ->where('month', $month)
                    ->isNotEmpty();

                if (! $hasActual) {
                    $missingCount++;

                    break;
                }
            }
        }

        $alerts = [];
        if ($missingCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'message' => $missingCount.' categorie con dati consuntivo mancanti per i mesi passati.',
            ];
        }

        $invoiceLimit = (float) Setting::getValue('annual_invoice_limit', '0');
        $invoicedCategoryIds = $categories
            ->filter(fn (Category $c): bool => $c->type === CategoryType::Income && (bool) $c->is_invoiced) // @phpstan-ignore identical.alwaysFalse
            ->pluck('id');

        $totalInvoicedBudget = round((float) $budgetEntries
            ->whereIn('category_id', $invoicedCategoryIds)
            ->sum('amount'), 2);

        if ($invoiceLimit > 0 && $totalInvoicedBudget > $invoiceLimit) {
            $alerts[] = [
                'type' => 'danger',
                'message' => 'Il budget delle entrate fatturate ('.number_format($totalInvoicedBudget, 2, ',', '.').' €) supera il limite annuale di '.number_format($invoiceLimit, 2, ',', '.').' €.',
            ];
        }

        $openingBalance = (float) Setting::getValue('opening_balance', '0');

        // Load reconciliation adjustments for this year, keyed by month
        $reconciliations = Reconciliation::query()
            ->where('year', $year)
            ->get()
            ->keyBy('month');

        $cumulativeBudget = $openingBalance;
        $cumulativeActual = $openingBalance;
        foreach ($monthlyData as &$data) {
            $cumulativeBudget += $data['budget_balance'];
            $data['cumulative_budget'] = round($cumulativeBudget, 2);

            if ($data['has_actual']) {
                $cumulativeActual += $data['actual_balance'];
                $data['cumulative_actual'] = round($cumulativeActual, 2);
            } else {
                $cumulativeActual += $data['budget_balance'];
                $data['cumulative_actual'] = null;
            }

            // Apply reconciliation adjustment at end of this month
            $reconciliation = $reconciliations->get($data['month']);
            if ($reconciliation) {
                $adj = (float) $reconciliation->adjustment;
                $cumulativeBudget += $adj;
                $cumulativeActual += $adj;
                $data['cumulative_budget'] = round($cumulativeBudget, 2);
                if ($data['cumulative_actual'] !== null) {
                    $data['cumulative_actual'] = round($cumulativeActual, 2);
                }
                $data['reconciliation_adjustment'] = $adj;
            }
        }
        unset($data);

        return Inertia::render('dashboard', [
            'year' => $year,
            'monthlyData' => $monthlyData,
            'categoryData' => $categoryData,
            'alerts' => $alerts,
            'currentMonth' => $currentMonth,
            'invoicedBudgetTotal' => $totalInvoicedBudget,
            'invoiceLimit' => $invoiceLimit,
            'openingBalance' => $openingBalance,
        ]);
    }
}
