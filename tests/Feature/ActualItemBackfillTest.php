<?php

use App\Models\ActualEntry;
use App\Models\ActualItem;
use App\Models\Category;
use App\Services\ActualItemBackfill;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('backfill turns manual amounts into one item per entry dated the first of the month', function (): void {
    $category = Category::factory()->expense()->create();
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 130, 'manual_amount' => 100, 'imported_amount' => 30, 'description' => 'Cena anniversario']);
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 4, 'amount' => 45, 'manual_amount' => 45, 'imported_amount' => 0, 'description' => null]);
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 5, 'amount' => 20, 'manual_amount' => 0, 'imported_amount' => 20]);

    expect(app(ActualItemBackfill::class)->fromManualAmounts())->toBe(2);

    $items = ActualItem::query()->orderBy('date')->get();
    expect($items)->toHaveCount(2)
        ->and($items[0]->date->toDateString())->toBe('2026-03-01')
        ->and($items[0]->description)->toBe('Cena anniversario')
        ->and($items[0]->amount)->toEqual('100.00')
        ->and($items[1]->description)->toBe('Voce manuale')
        ->and($items[1]->category_id)->toBe($category->id);
});

test('actual items belong to a category', function (): void {
    $item = ActualItem::factory()->create();

    expect($item->category->actualItems->pluck('id')->all())->toBe([$item->id]);
});
