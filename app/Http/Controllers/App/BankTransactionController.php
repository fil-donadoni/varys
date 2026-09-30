<?php

namespace App\Http\Controllers\App;

use App\Enums\BankImportStatus;
use App\Enums\CategorizationSource;
use App\Enums\CategoryType;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\ReassignBankTransactionRequest;
use App\Http\Requests\App\StoreTransactionCategoryRequest;
use App\Http\Requests\App\UpdateBankTransactionRequest;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankImport\ActualEntryRecalculator;
use App\Services\BankImport\BankImportReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class BankTransactionController extends Controller
{
    public function update(UpdateBankTransactionRequest $request, BankTransaction $bankTransaction, BankImportReviewer $reviewer): RedirectResponse
    {
        if ($bankTransaction->bankImport?->status === BankImportStatus::Completed) {
            return back()->withErrors(['transaction' => 'Import già confermato: elimina e reimporta per modificarlo.']);
        }

        $reviewer->assign(
            $bankTransaction,
            $request->filled('category_id') ? $request->integer('category_id') : null,
            $request->boolean('exclude'),
            $request->boolean('apply_to_merchant'),
        );

        return back();
    }

    /**
     * Creates a category on the fly from the review page and assigns it right away.
     */
    public function storeCategory(StoreTransactionCategoryRequest $request, BankTransaction $bankTransaction, BankImportReviewer $reviewer): RedirectResponse
    {
        if ($bankTransaction->bankImport?->status === BankImportStatus::Completed) {
            return back()->withErrors(['transaction' => 'Import già confermato: elimina e reimporta per modificarlo.']);
        }

        $type = CategoryType::from((string) $request->string('type'));

        // An outgoing movement can only go into an expense category.
        if ($type === CategoryType::Income && (float) $bankTransaction->amount < 0) {
            return back()->withErrors(['type' => 'Un\'uscita può andare solo in una categoria di spesa.']);
        }

        $category = Category::query()->create([
            'name' => trim((string) $request->string('name')),
            'type' => $type,
            'color' => $request->input('color'),
            'sort_order' => (int) Category::query()->where('type', $type)->max('sort_order') + 1,
            'is_invoiced' => false,
        ]);

        $reviewer->assign($bankTransaction, $category->id, false, $request->boolean('apply_to_merchant'));

        return back()->with('notice', "Categoria \"{$category->name}\" creata e assegnata.");
    }

    /**
     * Fixes an already confirmed movement from the actual page: other category or excluded.
     */
    public function reassign(ReassignBankTransactionRequest $request, BankTransaction $bankTransaction, ActualEntryRecalculator $recalculator): RedirectResponse
    {
        if ($bankTransaction->status !== TransactionStatus::Confirmed) {
            return back()->withErrors(['transaction' => 'Si possono modificare qui solo i movimenti confermati.']);
        }

        $exclude = $request->boolean('exclude');
        $categoryId = $exclude ? null : $request->integer('category_id');

        if ($categoryId !== null && (float) $bankTransaction->amount < 0 && Category::query()->whereKey($categoryId)->value('type') === CategoryType::Income) {
            return back()->withErrors(['transaction' => 'Un\'uscita può andare solo in una categoria di spesa.']);
        }

        $previousCategoryId = $bankTransaction->category_id;

        DB::transaction(function () use ($bankTransaction, $exclude, $categoryId, $previousCategoryId, $recalculator): void {
            $bankTransaction->update([
                'category_id' => $categoryId,
                'categorization_source' => CategorizationSource::Manual,
                'confidence' => null,
                'status' => $exclude ? TransactionStatus::Excluded : TransactionStatus::Confirmed,
            ]);

            $date = $bankTransaction->accounting_date;
            foreach (array_unique(array_filter([$previousCategoryId, $categoryId])) as $affected) {
                $recalculator->recalculate($affected, $date->year, $date->month);
            }
        });

        return back()->with('success', $exclude ? 'Movimento escluso.' : 'Movimento spostato.');
    }
}
