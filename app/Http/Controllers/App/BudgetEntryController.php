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
        $invoicedCategoryIds = $categories
            ->filter(fn (Category $c): bool => $c->type === CategoryType::Income && (bool) $c->is_invoiced) // @phpstan-ignore identical.alwaysFalse
            ->pluck('id')
            ->all();

        return Inertia::render('budget/index', [
            'year' => $year,
            'categories' => $categories,
            'entries' => $entries,
            'invoicedCategoryIds' => $invoicedCategoryIds,
            'invoiceLimit' => $invoiceLimit,
        ]);
    }

    public function bulkUpsert(BulkUpsertBudgetRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var array<int, array{category_id: int, month: int, amount: float|null, notes?: string|null}> $entries */
        $entries = $validated['entries'];

        foreach ($entries as $entry) {
            if ($entry['amount'] === null) {
                BudgetEntry::query()
                    ->where('category_id', $entry['category_id'])
                    ->where('year', $validated['year'])
                    ->where('month', $entry['month'])
                    ->delete();

                continue;
            }

            $budgetEntry = BudgetEntry::updateOrCreate(
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

            $budgetEntry->items()->delete();
        }

        return redirect()->route('budget.index', ['year' => $validated['year']])
            ->with('success', 'Budget aggiornato con successo.');
    }

    public function syncItems(SyncBudgetEntryItemsRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        /** @var list<array{description: string, amount: float|string}> $items */
        $items = $validated['items'];

        $attributes = [
            'category_id' => $validated['category_id'],
            'year' => $validated['year'],
            'month' => $validated['month'],
        ];

        DB::transaction(function () use ($attributes, $items): void {
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
                ]);
            }
        });

        return redirect()->route('budget.index', ['year' => $validated['year']])
            ->with('success', 'Budget aggiornato con successo.');
    }
}
