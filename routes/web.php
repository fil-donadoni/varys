<?php

use App\Http\Controllers\App\ActualEntryController;
use App\Http\Controllers\App\BudgetEntryController;
use App\Http\Controllers\App\CategoryController;
use App\Http\Controllers\App\DashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');

Route::resource('categories', CategoryController::class)->except(['show']);

Route::get('budget', [BudgetEntryController::class, 'index'])->name('budget.index');
Route::post('budget/bulk', [BudgetEntryController::class, 'bulkUpsert'])->name('budget.bulk-upsert');

Route::get('actual', [ActualEntryController::class, 'index'])->name('actual.index');
Route::post('actual/bulk', [ActualEntryController::class, 'bulkUpsert'])->name('actual.bulk-upsert');
