<?php

use App\Models\BudgetEntry;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('zero amount is persisted', function (): void {
    $category = Category::factory()->create();
    BudgetEntry::factory()->create([
        'category_id' => $category->id,
        'year' => 2026,
        'month' => 3,
        'amount' => 500,
    ]);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $category->id, 'month' => 3, 'amount' => '0']],
    ])->assertRedirect();

    expect(BudgetEntry::where('category_id', $category->id)->where('year', 2026)->where('month', 3)->sole()->amount)
        ->toEqual('0.00');
});

test('empty amount deletes the entry', function (): void {
    $category = Category::factory()->create();
    BudgetEntry::factory()->create([
        'category_id' => $category->id,
        'year' => 2026,
        'month' => 3,
        'amount' => 500,
    ]);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $category->id, 'month' => 3, 'amount' => null]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::where('category_id', $category->id)->where('year', 2026)->where('month', 3)->exists())
        ->toBeFalse();
});
