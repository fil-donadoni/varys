<?php

use App\Models\ActualEntry;
use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function exportBackup(): string
{
    $path = tempnam(sys_get_temp_dir(), 'varys_test_').'.zip';
    file_put_contents($path, test()->get('/data/export')->streamedContent());

    return $path;
}

function importBackup(string $path): void
{
    test()->post('/data/import', ['file' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();
    unlink($path);
}

test('backup preserves actual items', function (): void {
    $category = Category::factory()->expense()->create();
    ActualItem::factory()->create(['category_id' => $category->id, 'date' => '2026-08-15', 'description' => 'Cena, "amici"', 'amount' => 60.5]);

    importBackup(exportBackup());

    $item = ActualItem::sole();
    expect($item->description)->toBe('Cena, "amici"')
        ->and($item->date->toDateString())->toBe('2026-08-15')
        ->and($item->amount)->toEqual('60.50');

    ActualItem::factory()->create(['category_id' => $category->id]); // sequence was reset
});

test('old backups without actual items get items from manual amounts', function (): void {
    $category = Category::factory()->expense()->create();
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 70, 'manual_amount' => 70, 'imported_amount' => 0, 'description' => 'Contanti']);
    $path = exportBackup();

    $zip = new ZipArchive;
    $zip->open($path);
    $zip->deleteName('actual_items.csv');
    $zip->close();

    importBackup($path);

    expect(ActualItem::sole()->description)->toBe('Contanti')
        ->and(ActualItem::sole()->amount)->toEqual('70.00')
        ->and(ActualEntry::sole()->amount)->toEqual('70.00');
});
