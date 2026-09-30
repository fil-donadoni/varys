<?php

use App\Models\ActualEntry;
use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function entryFor(Category $category, int $year, int $month): ?ActualEntry
{
    return ActualEntry::query()->where(['category_id' => $category->id, 'year' => $year, 'month' => $month])->first();
}

test('storing an item updates the month actual', function (): void {
    $category = Category::factory()->expense()->create();

    $this->post('/actual-items', ['category_id' => $category->id, 'date' => '2026-08-15', 'description' => 'Cena amici', 'amount' => '60,50'])
        ->assertSessionHasErrors('amount');

    $this->post('/actual-items', ['category_id' => $category->id, 'date' => '2026-08-15', 'description' => 'Cena amici', 'amount' => '60.50'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(ActualItem::sole()->description)->toBe('Cena amici')
        ->and(entryFor($category, 2026, 8)?->manual_amount)->toEqual('60.50')
        ->and(entryFor($category, 2026, 8)?->amount)->toEqual('60.50');
});

test('items need a description, a date and a non zero amount', function (): void {
    $category = Category::factory()->expense()->create();

    $this->post('/actual-items', ['category_id' => $category->id, 'date' => 'ieri', 'description' => '', 'amount' => '0.00'])
        ->assertSessionHasErrors(['date', 'description', 'amount']);

    expect(ActualItem::count())->toBe(0);
});

test('negative items are allowed as refunds', function (): void {
    $category = Category::factory()->expense()->create();
    ActualItem::factory()->create(['category_id' => $category->id, 'date' => '2026-08-01', 'amount' => 100]);

    $this->post('/actual-items', ['category_id' => $category->id, 'date' => '2026-08-20', 'description' => 'Rimborso', 'amount' => '-30'])
        ->assertSessionHasNoErrors();

    expect(entryFor($category, 2026, 8)?->amount)->toEqual('70.00');
});

test('moving an item to another month and category recalculates both sides', function (): void {
    $from = Category::factory()->expense()->create();
    $to = Category::factory()->expense()->create();
    $this->post('/actual-items', ['category_id' => $from->id, 'date' => '2026-08-15', 'description' => 'Pranzo', 'amount' => '25']);
    $item = ActualItem::sole();

    $this->put("/actual-items/{$item->id}", ['category_id' => $to->id, 'date' => '2026-09-02', 'description' => 'Pranzo lavoro', 'amount' => '30'])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(entryFor($from, 2026, 8))->toBeNull()
        ->and(entryFor($to, 2026, 9)?->amount)->toEqual('30.00')
        ->and($item->fresh()?->description)->toBe('Pranzo lavoro');
});

test('deleting an item recalculates the month', function (): void {
    $category = Category::factory()->expense()->create();
    $this->post('/actual-items', ['category_id' => $category->id, 'date' => '2026-08-15', 'description' => 'Cena', 'amount' => '40']);

    $this->delete('/actual-items/'.ActualItem::sole()->id)->assertRedirect();

    expect(ActualItem::count())->toBe(0)
        ->and(entryFor($category, 2026, 8))->toBeNull();
});
