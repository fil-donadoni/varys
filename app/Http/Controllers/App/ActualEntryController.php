<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
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
}
