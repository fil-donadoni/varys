<?php

use App\Enums\CategoryType;
use App\Models\BudgetEntry;
use App\Models\BudgetEntryItem;
use App\Models\Category;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function incomeCategory(bool $invoiced): Category
{
    return Category::factory()->create(['type' => CategoryType::Income, 'is_invoiced' => $invoiced]);
}

test('a new entry inherits the invoiced default of its category', function (): void {
    $invoiced = incomeCategory(true);
    $plain = incomeCategory(false);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [
            ['category_id' => $invoiced->id, 'month' => 3, 'amount' => '1000'],
            ['category_id' => $plain->id, 'month' => 3, 'amount' => '1000'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::where('category_id', $invoiced->id)->sole()->is_invoiced)->toBeTrue()
        ->and(BudgetEntry::where('category_id', $plain->id)->sole()->is_invoiced)->toBeFalse();
});

test('an explicit invoiced flag overrides the category default', function (): void {
    $category = incomeCategory(true);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $category->id, 'month' => 3, 'amount' => '1000', 'is_invoiced' => false]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::sole()->is_invoiced)->toBeFalse();
});

test('changing the amount keeps the entry invoiced flag', function (): void {
    $category = incomeCategory(false);
    BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 500, 'is_invoiced' => true]);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $category->id, 'month' => 3, 'amount' => '750']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = BudgetEntry::sole();
    expect($entry->amount)->toEqual('750.00')
        ->and($entry->is_invoiced)->toBeTrue();
});

test('expense entries are never invoiced', function (): void {
    $category = Category::factory()->create(['type' => CategoryType::Expense, 'is_invoiced' => true]);

    $this->post('/budget/bulk', [
        'year' => 2026,
        'entries' => [['category_id' => $category->id, 'month' => 3, 'amount' => '100', 'is_invoiced' => true]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::sole()->is_invoiced)->toBeFalse();
});

test('items inherit the category default unless flagged explicitly', function (): void {
    $category = incomeCategory(true);

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $category->id,
        'items' => [
            ['description' => 'Cliente A', 'amount' => '300'],
            ['description' => 'Cliente B', 'amount' => '200', 'is_invoiced' => false],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::sole()->items->pluck('is_invoiced')->all())->toBe([true, false]);
});

test('expense items are never invoiced', function (): void {
    $category = Category::factory()->create(['type' => CategoryType::Expense, 'is_invoiced' => true]);

    $this->put('/budget/items', [
        'year' => 2026,
        'month' => 4,
        'category_id' => $category->id,
        'items' => [['description' => 'Riga', 'amount' => '300', 'is_invoiced' => true]],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(BudgetEntry::sole()->items->pluck('is_invoiced')->all())->toBe([false]);
});

test('budget page exposes invoiced flags on entries and items', function (): void {
    $category = incomeCategory(false);
    $entry = BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 4, 'is_invoiced' => true]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'is_invoiced' => true]);

    $this->get('/budget?year=2026')
        ->assertInertia(fn ($page) => $page
            ->where("entries.{$category->id}.4.is_invoiced", true)
            ->where("entries.{$category->id}.4.items.0.is_invoiced", true)
            ->missing('invoicedCategoryIds'));
});

test('dashboard sums whole flagged entries plus flagged items of split entries', function (): void {
    Setting::setValue('annual_invoice_limit', '85000');
    $category = incomeCategory(true);

    BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 1, 'amount' => 1000, 'is_invoiced' => true]);
    BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 2, 'amount' => 500, 'is_invoiced' => false]);

    // Split entry: the entry flag is ignored, only the item flags count.
    $split = BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 500, 'is_invoiced' => true]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $split->id, 'amount' => 300, 'is_invoiced' => true]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $split->id, 'amount' => 200, 'is_invoiced' => false]);

    $this->get('/?year=2026')
        ->assertInertia(fn ($page) => $page->where('invoicedBudgetTotal', fn ($total) => (float) $total === 1300.0));
});

test('backup export and import preserve invoiced flags', function (): void {
    Setting::setValue('annual_invoice_limit', '85000');
    $category = incomeCategory(false);
    $entry = BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 4, 'amount' => 500, 'is_invoiced' => true]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'amount' => 300, 'is_invoiced' => true]);
    BudgetEntryItem::factory()->create(['budget_entry_id' => $entry->id, 'amount' => 200, 'is_invoiced' => false]);

    $zipContent = $this->get('/data/export')->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'varys_test_').'.zip';
    file_put_contents($path, $zipContent);

    BudgetEntry::query()->update(['is_invoiced' => false]);
    BudgetEntryItem::query()->update(['is_invoiced' => false]);

    $this->post('/data/import', ['file' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();

    unlink($path);

    expect(BudgetEntry::sole()->is_invoiced)->toBeTrue()
        ->and(BudgetEntry::sole()->items->pluck('is_invoiced')->all())->toBe([true, false]);
});

test('importing an old backup without invoiced columns falls back to the category default', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'varys_test_').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('settings.csv', "key,value\n");
    $zip->addFromString('categories.csv', "id,name,type,is_invoiced,color,sort_order\n1,Freelance,income,1,,1\n2,Altro,income,0,,2\n");
    $zip->addFromString('budget_entries.csv', "id,category_id,year,month,amount,notes\n1,1,2026,1,1000,\n2,2,2026,1,200,\n");
    $zip->addFromString('budget_entry_items.csv', "id,budget_entry_id,description,amount,sort_order\n1,1,Cliente,1000,0\n2,2,Altro,200,0\n");
    $zip->close();

    $this->post('/data/import', ['file' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();

    unlink($path);

    expect(BudgetEntry::find(1)?->is_invoiced)->toBeTrue()
        ->and(BudgetEntry::find(2)?->is_invoiced)->toBeFalse()
        ->and(BudgetEntryItem::find(1)?->is_invoiced)->toBeTrue()
        ->and(BudgetEntryItem::find(2)?->is_invoiced)->toBeFalse();
});
