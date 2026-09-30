<?php

use App\Enums\TransactionStatus;
use App\Models\ActualItem;
use App\Models\BankTransaction;
use App\Models\BudgetEntry;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('month tab lists manual items and confirmed movements per category', function (): void {
    $dining = Category::factory()->expense()->create();
    BudgetEntry::factory()->create(['category_id' => $dining->id, 'year' => 2026, 'month' => 8, 'amount' => 300]);
    $item = ActualItem::factory()->create(['category_id' => $dining->id, 'date' => '2026-08-15', 'description' => 'Cena amici', 'amount' => 60]);
    $movement = BankTransaction::factory()->confirmed()->create(['category_id' => $dining->id, 'accounting_date' => '2026-08-12', 'amount' => -45, 'merchant_label' => 'TRATTORIA X']);
    BankTransaction::factory()->create(['category_id' => $dining->id, 'accounting_date' => '2026-08-13', 'amount' => -99, 'status' => TransactionStatus::Auto]);
    ActualItem::factory()->create(['category_id' => $dining->id, 'date' => '2026-09-01']);

    $this->get("/actual?year=2026&month=8&category={$dining->id}")
        ->assertInertia(fn ($page) => $page
            ->component('actual/index')
            ->where('tab', 'month')
            ->where('expandedCategoryId', $dining->id)
            ->where("budgets.{$dining->id}", 300)
            ->has("lines.{$dining->id}", 2)
            ->where("lines.{$dining->id}.0.key", "bank-{$movement->id}")
            ->where("lines.{$dining->id}.0.description", 'TRATTORIA X')
            ->where("lines.{$dining->id}.0.amount", 45)
            ->where("lines.{$dining->id}.0.bank_amount", -45)
            ->where("lines.{$dining->id}.1.key", "manual-{$item->id}")
            ->where("lines.{$dining->id}.1.date", '2026-08-15')
            ->where("lines.{$dining->id}.1.amount", 60)
            ->has('report.rows', 1));
});

test('year tab is selected from the query string', function (): void {
    $this->get('/actual?tab=year&year=2025')
        ->assertInertia(fn ($page) => $page->where('tab', 'year')->where('year', 2025)->where('report.closed_months', 12));
});
