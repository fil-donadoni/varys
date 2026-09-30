<?php

use App\Http\Controllers\App\ActualEntryController;
use App\Http\Controllers\App\BankImportController;
use App\Http\Controllers\App\BankTransactionController;
use App\Http\Controllers\App\BudgetEntryController;
use App\Http\Controllers\App\CategoryController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\DataExportController;
use App\Http\Controllers\App\ReconciliationController;
use App\Http\Controllers\App\SettingController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('categories', CategoryController::class)->except(['show']);

Route::get('budget', [BudgetEntryController::class, 'index'])->name('budget.index');
Route::post('budget/bulk', [BudgetEntryController::class, 'bulkUpsert'])->name('budget.bulk-upsert');
Route::put('budget/items', [BudgetEntryController::class, 'syncItems'])->name('budget.sync-items');

Route::get('actual', [ActualEntryController::class, 'index'])->name('actual.index');
Route::post('actual/bulk', [ActualEntryController::class, 'bulkUpsert'])->name('actual.bulk-upsert');

Route::get('reconciliations', [ReconciliationController::class, 'index'])->name('reconciliations.index');
Route::post('reconciliations/calculate', [ReconciliationController::class, 'calculateBalance'])->name('reconciliations.calculate');
Route::post('reconciliations', [ReconciliationController::class, 'store'])->name('reconciliations.store');
Route::delete('reconciliations/{reconciliation}', [ReconciliationController::class, 'destroy'])->name('reconciliations.destroy');

Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
Route::put('settings', [SettingController::class, 'update'])->name('settings.update');

Route::resource('bank-imports', BankImportController::class)->only(['index', 'store', 'show', 'destroy']);
Route::post('bank-imports/{bank_import}/categorize', [BankImportController::class, 'categorize'])->name('bank-imports.categorize');
Route::post('bank-imports/{bank_import}/complete', [BankImportController::class, 'complete'])->name('bank-imports.complete');
Route::patch('bank-transactions/{bank_transaction}', [BankTransactionController::class, 'update'])->name('bank-transactions.update');
Route::post('bank-transactions/{bank_transaction}/category', [BankTransactionController::class, 'storeCategory'])->name('bank-transactions.store-category');

Route::get('data/export', [DataExportController::class, 'export'])->name('data.export');
Route::post('data/import', [DataExportController::class, 'import'])->name('data.import');
