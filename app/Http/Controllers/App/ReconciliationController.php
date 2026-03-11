<?php

namespace App\Http\Controllers\App;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReconciliationController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->query('year', (string) now()->year);

        $reconciliations = Reconciliation::query()
            ->where('year', $year)
            ->orderBy('month')
            ->get();

        return Inertia::render('reconciliations/index', [
            'year' => $year,
            'reconciliations' => $reconciliations,
        ]);
    }

    public function calculateBalance(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];

        $calculatedBalance = $this->computeCumulativeBalance($year, $month);

        return response()->json([
            'calculated_balance' => round($calculatedBalance, 2),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'declared_balance' => ['required', 'numeric'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $year = (int) $validated['year'];
        $month = (int) $validated['month'];
        $declaredBalance = (float) $validated['declared_balance'];

        $calculatedBalance = $this->computeCumulativeBalance($year, $month);
        $adjustment = round($declaredBalance - $calculatedBalance, 2);

        Reconciliation::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'declared_balance' => $declaredBalance,
                'calculated_balance' => round($calculatedBalance, 2),
                'adjustment' => $adjustment,
                'notes' => $validated['notes'] ?? null,
            ],
        );

        return redirect()->route('reconciliations.index', ['year' => $year])
            ->with('success', 'Riconciliazione salvata.');
    }

    public function destroy(Reconciliation $reconciliation): RedirectResponse
    {
        $year = $reconciliation->year;
        $reconciliation->delete();

        return redirect()->route('reconciliations.index', ['year' => $year])
            ->with('success', 'Riconciliazione eliminata.');
    }

    private function computeCumulativeBalance(int $year, int $month): float
    {
        $openingBalance = (float) Setting::getValue('opening_balance', '0');
        $categories = Category::query()->get();

        /** @var array<int, CategoryType> $categoryTypeMap */
        $categoryTypeMap = $categories->pluck('type', 'id')->all();

        // Sum all previous reconciliation adjustments (before this year/month)
        $priorAdjustments = (float) Reconciliation::query()
            ->where(function ($q) use ($year, $month): void {
                $q->where('year', '<', $year)
                    ->orWhere(function ($q2) use ($year, $month): void {
                        $q2->where('year', $year)->where('month', '<', $month);
                    });
            })
            ->sum('adjustment');

        // Sum actual entries for the current year up to the given month
        $actualEntries = ActualEntry::query()
            ->where('year', $year)
            ->where('month', '<=', $month)
            ->get();

        $actualBalance = 0.0;
        foreach ($actualEntries as $entry) {
            $type = $categoryTypeMap[$entry->category_id] ?? null;
            if ($type === CategoryType::Income) {
                $actualBalance += (float) $entry->amount;
            } elseif ($type === CategoryType::Expense) {
                $actualBalance -= (float) $entry->amount;
            }
        }

        // For months without actual data, use budget entries
        $budgetEntries = BudgetEntry::query()
            ->where('year', $year)
            ->where('month', '<=', $month)
            ->get();

        $actualMonths = $actualEntries->pluck('month')->unique()->all();

        $budgetFallback = 0.0;
        foreach ($budgetEntries as $entry) {
            if (in_array($entry->month, $actualMonths)) {
                continue;
            }
            $type = $categoryTypeMap[$entry->category_id] ?? null;
            if ($type === CategoryType::Income) {
                $budgetFallback += (float) $entry->amount;
            } elseif ($type === CategoryType::Expense) {
                $budgetFallback -= (float) $entry->amount;
            }
        }

        return $openingBalance + $actualBalance + $budgetFallback + $priorAdjustments;
    }
}
