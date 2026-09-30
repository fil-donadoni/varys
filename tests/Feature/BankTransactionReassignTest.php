<?php

use App\Enums\CategorizationSource;
use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function completedTransaction(Category $category, float $amount): BankTransaction
{
    $transaction = BankTransaction::factory()->confirmed()->create([
        'bank_import_id' => BankImport::factory()->completed(),
        'category_id' => $category->id,
        'accounting_date' => '2026-08-12',
        'amount' => $amount,
    ]);
    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 8);

    return $transaction;
}

function actualOf(Category $category): ?string
{
    return ActualEntry::query()->where(['category_id' => $category->id, 'year' => 2026, 'month' => 8])->value('amount');
}

test('a confirmed transaction of a completed import can move to another category', function (): void {
    $dining = Category::factory()->expense()->create();
    $groceries = Category::factory()->expense()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => $groceries->id, 'exclude' => false])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($transaction->fresh()?->category_id)->toBe($groceries->id)
        ->and($transaction->fresh()?->status)->toBe(TransactionStatus::Confirmed)
        ->and($transaction->fresh()?->categorization_source)->toBe(CategorizationSource::Manual)
        ->and(actualOf($dining))->toBeNull()
        ->and(actualOf($groceries))->toEqual('45.00');
});

test('excluding a confirmed transaction removes it from the actual', function (): void {
    $dining = Category::factory()->expense()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => null, 'exclude' => true])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()?->status)->toBe(TransactionStatus::Excluded)
        ->and($transaction->fresh()?->category_id)->toBeNull()
        ->and(actualOf($dining))->toBeNull();
});

test('an outgoing movement cannot go into an income category', function (): void {
    $dining = Category::factory()->expense()->create();
    $salary = Category::factory()->income()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => $salary->id, 'exclude' => false])
        ->assertSessionHasErrors('transaction');

    expect($transaction->fresh()?->category_id)->toBe($dining->id);
});

test('only confirmed transactions can be reassigned and a target is required', function (): void {
    $dining = Category::factory()->expense()->create();
    $pending = BankTransaction::factory()->create(['category_id' => $dining->id, 'status' => TransactionStatus::Auto]);
    $confirmed = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$pending->id}/reassign", ['category_id' => $dining->id, 'exclude' => false])
        ->assertSessionHasErrors('transaction');

    $this->patch("/bank-transactions/{$confirmed->id}/reassign", ['category_id' => null, 'exclude' => false])
        ->assertSessionHasErrors('category_id');
});
