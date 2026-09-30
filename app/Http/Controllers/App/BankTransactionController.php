<?php

namespace App\Http\Controllers\App;

use App\Enums\BankImportStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\UpdateBankTransactionRequest;
use App\Models\BankTransaction;
use App\Services\BankImport\BankImportReviewer;
use Illuminate\Http\RedirectResponse;

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
}
