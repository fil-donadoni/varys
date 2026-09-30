<?php

use App\Models\BudgetEntry;
use App\Models\BudgetEntryItem;
use App\Models\Category;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

test('items create the entry with amount equal to their sum', function (): void {
    $category = Category::factory()->create();

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $category->id,
        'items' => [
            ['description' => 'Cliente A', 'amount' => '1200'],
            ['description' => 'Cliente B', 'amount' => '1800.50'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = BudgetEntry::where('category_id', $category->id)->where('year', 2026)->where('month', 4)->sole();

    expect($entry->amount)->toEqual('3000.50')
        ->and($entry->items->pluck('description')->all())->toBe(['Cliente A', 'Cliente B'])
        ->and($entry->items->pluck('sort_order')->all())->toBe([0, 1]);
});

test('items replace existing items and update the amount', function (): void {
    $entry = BudgetEntry::factory()->create(['year' => 2026, 'month' => 4, 'amount' => 500]);
    BudgetEntryItem::factory()->count(3)->create(['budget_entry_id' => $entry->id]);

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $entry->category_id,
        'items' => [['description' => 'Unica', 'amount' => '750']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry->refresh();

    expect($entry->amount)->toEqual('750.00')
        ->and($entry->items)->toHaveCount(1)
        ->and(BudgetEntryItem::count())->toBe(1);
});

test('empty items delete the entry', function (): void {
    $entry = BudgetEntry::factory()->create(['year' => 2026, 'month' => 4]);
    BudgetEntryItem::factory()->count(2)->create(['budget_entry_id' => $entry->id]);

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $entry->category_id,
        'items' => [],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::count())->toBe(0)
        ->and(BudgetEntryItem::count())->toBe(0);
});

test('items require description and non negative amount', function (): void {
    $category = Category::factory()->create();

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $category->id,
        'items' => [['description' => '', 'amount' => '-5']],
    ])->assertSessionHasErrors(['items.0.description', 'items.0.amount']);

    expect(BudgetEntry::count())->toBe(0);
});

test('setting a single amount removes existing items', function (): void {
    $entry = BudgetEntry::factory()->create(['year' => 2026, 'month' => 4]);
    BudgetEntryItem::factory()->count(2)->create(['budget_entry_id' => $entry->id]);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $entry->category_id, 'month' => 4, 'amount' => '999']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($entry->refresh()->amount)->toEqual('999.00')
        ->and(BudgetEntryItem::count())->toBe(0);
});

test('budget page exposes entry items', function (): void {
    $entry = BudgetEntry::factory()->create(['year' => 2026, 'month' => 4]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'description' => 'Causale X']);

    $this->get('/budget?year=2026')
        ->assertInertia(fn ($page) => $page
            ->where("entries.{$entry->category_id}.4.items.0.description", 'Causale X'));
});

test('backup export and import preserve entry items', function (): void {
    Setting::setValue('annual_invoice_limit', '85000');
    $entry = BudgetEntry::factory()->create(['year' => 2026, 'month' => 4, 'amount' => 3000]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'description' => 'Cliente A', 'amount' => 1200, 'sort_order' => 0]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'description' => 'Cliente B, saldo', 'amount' => 1800, 'sort_order' => 1]);

    $zipContent = $this->get('/data/export')->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'varys_test_').'.zip';
    file_put_contents($path, $zipContent);

    BudgetEntryItem::query()->delete();

    $this->post('/data/import', ['file' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();

    unlink($path);

    expect(BudgetEntry::sole()->items->pluck('description')->all())->toBe(['Cliente A', 'Cliente B, saldo']);

    $newItem = BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id]);
    expect($newItem->id)->toBeGreaterThan(2);
});
