<?php

namespace App\Http\Controllers\App;

use App\Enums\CategoryType;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\BulkUpsertBudgetRequest;
use App\Http\Requests\App\SyncBudgetEntryItemsRequest;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->with(['category', 'items'])
            ->get()
            ->groupBy('category_id')
            ->map(fn ($entries) => $entries->keyBy('month'))
            ->all();

        $invoiceLimit = (float) Setting::getValue('annual_invoice_limit', '0');

        return Inertia::render('budget/index', [
            'year' => $year,
            'categories' => $categories,
            'entries' => $entries,
            'invoiceLimit' => $invoiceLimit,
        ]);
    }

    public function bulkUpsert(BulkUpsertBudgetRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var array<int, array{category_id: int, month: int, amount: float|null, notes?: string|null, is_invoiced?: bool}> $entries */
        $entries = $validated['entries'];

        $categories = Category::query()->findMany(array_column($entries, 'category_id'))->keyBy('id');

        foreach ($entries as $entry) {
            if ($entry['amount'] === null) {
                BudgetEntry::query()
                    ->where('category_id', $entry['category_id'])
                    ->where('year', $validated['year'])
                    ->where('month', $entry['month'])
                    ->delete();

                continue;
            }

            /** @var Category $category */
            $category = $categories->get($entry['category_id']);

            $attributes = [
                'category_id' => $entry['category_id'],
                'year' => $validated['year'],
                'month' => $entry['month'],
            ];

            $budgetEntry = BudgetEntry::query()->firstOrNew($attributes);
            $budgetEntry->amount = $entry['amount'];
            $budgetEntry->notes = $entry['notes'] ?? null;
            $budgetEntry->is_invoiced = $this->resolveInvoiced($category, $entry['is_invoiced'] ?? null, $budgetEntry->exists ? (bool) $budgetEntry->is_invoiced : null);
            $budgetEntry->save();

            $budgetEntry->items()->delete();
        }

        return redirect()->route('budget.index', ['year' => $validated['year']])
            ->with('success', 'Budget aggiornato con successo.');
    }

    public function syncItems(SyncBudgetEntryItemsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var list<array{description: string, amount: float|string, is_invoiced?: bool}> $items */
        $items = $validated['items'];

        /** @var Category $category */
        $category = Category::query()->findOrFail($validated['category_id']);

        $attributes = [
            'category_id' => $validated['category_id'],
            'year' => $validated['year'],
            'month' => $validated['month'],
        ];

        DB::transaction(function () use ($attributes, $items, $category): void {
            if ($items === []) {
                BudgetEntry::query()->where($attributes)->delete();

                return;
            }

            $total = array_reduce($items, fn (float $sum, array $item): float => $sum + (float) $item['amount'], 0.0);

            $budgetEntry = BudgetEntry::updateOrCreate($attributes, ['amount' => round($total, 2)]);

            $budgetEntry->items()->delete();

            foreach ($items as $index => $item) {
                $budgetEntry->items()->create([
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'sort_order' => $index,
                    'is_invoiced' => $this->resolveInvoiced($category, $item['is_invoiced'] ?? null),
                ]);
            }
        });

        return redirect()->route('budget.index', ['year' => $validated['year']])
            ->with('success', 'Budget aggiornato con successo.');
    }

    /**
     * Expense lines are never invoiced; income lines take the explicit flag,
     * else keep their current value, else the category default.
     */
    private function resolveInvoiced(Category $category, ?bool $explicit, ?bool $current = null): bool
    {
        if ($category->type !== CategoryType::Income) {
            return false;
        }

        return $explicit ?? $current ?? (bool) $category->is_invoiced;
    }
}
