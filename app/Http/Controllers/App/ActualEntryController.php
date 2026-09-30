<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BulkUpsertActualRequest;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActualEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $month = (int) $request->query('month', (string) now()->month);

        $categories = Category::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        $entries = ActualEntry::query()
            ->where('year', $year)
            ->where('month', $month)
            ->with('category')
            ->get()
            ->keyBy('category_id')
            ->all();

        $budgetEntries = BudgetEntry::query()
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('category_id')
            ->all();

        return Inertia::render('actual/index', [
            'year' => $year,
            'month' => $month,
            'categories' => $categories,
            'entries' => $entries,
            'budgetEntries' => $budgetEntries,
        ]);
    }

    public function bulkUpsert(BulkUpsertActualRequest $request, ActualEntryRecalculator $recalculator): RedirectResponse
    {
        $validated = $request->validated();

        /** @var array<int, array{category_id: int, amount: float, description?: string|null, notes?: string|null}> $entries */
        $entries = $validated['entries'];

        // "amount" from the form is the manual part; the imported part comes from bank transactions.
        foreach ($entries as $entry) {
            $attributes = ['category_id' => $entry['category_id'], 'year' => $validated['year'], 'month' => $validated['month']];
            $existing = ActualEntry::query()->where($attributes)->first();

            if ($existing === null && (float) $entry['amount'] === 0.0) {
                continue;
            }

            $existing ??= new ActualEntry([...$attributes, 'imported_amount' => 0]);
            $existing->fill([
                'manual_amount' => $entry['amount'],
                'description' => $entry['description'] ?? null,
                'notes' => $entry['notes'] ?? null,
            ])->save();

            $recalculator->recalculate($entry['category_id'], $validated['year'], $validated['month']);
        }

        return redirect()->route('actual.index', ['year' => $validated['year'], 'month' => $validated['month']])
            ->with('success', 'Consuntivo aggiornato con successo.');
    }
}
