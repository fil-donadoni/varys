<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\BulkUpsertBudgetRequest;
use App\Models\BudgetEntry;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetEntryController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->query('year', (string) now()->year);

        $categories = Category::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        $entries = BudgetEntry::query()
            ->where('year', $year)
            ->with('category')
            ->get()
            ->groupBy('category_id')
            ->map(fn ($entries) => $entries->keyBy('month'))
            ->all();

        return Inertia::render('budget/index', [
            'year' => $year,
            'categories' => $categories,
            'entries' => $entries,
        ]);
    }

    public function bulkUpsert(BulkUpsertBudgetRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var array<int, array{category_id: int, month: int, amount: float, notes?: string|null}> $entries */
        $entries = $validated['entries'];

        foreach ($entries as $entry) {
            BudgetEntry::updateOrCreate(
                [
                    'category_id' => $entry['category_id'],
                    'year' => $validated['year'],
                    'month' => $entry['month'],
                ],
                [
                    'amount' => $entry['amount'],
                    'notes' => $entry['notes'] ?? null,
                ],
            );
        }

        return redirect()->route('budget.index', ['year' => $validated['year']])
            ->with('success', 'Budget aggiornato con successo.');
    }
}
