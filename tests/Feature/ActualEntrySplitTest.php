<?php

use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function confirmedTransaction(Category $category, string $date, float $amount): BankTransaction
{
    return BankTransaction::factory()->confirmed()->create([
        'category_id' => $category->id,
        'operation_date' => $date,
        'amount' => $amount,
    ]);
}

test('recalculation sums confirmed transactions of the month on top of the manual amount', function (): void {
    $category = Category::factory()->expense()->create();
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 100, 'manual_amount' => 100, 'imported_amount' => 0]);

    confirmedTransaction($category, '2026-03-01', 20.5);
    confirmedTransaction($category, '2026-03-31', 10);
    confirmedTransaction($category, '2026-04-01', 99);
    BankTransaction::factory()->create(['category_id' => $category->id, 'operation_date' => '2026-03-10', 'amount' => 50, 'status' => TransactionStatus::ToReview]);

    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 3);

    $entry = ActualEntry::sole();
    expect($entry->manual_amount)->toEqual('100.00')
        ->and($entry->imported_amount)->toEqual('30.50')
        ->and($entry->amount)->toEqual('130.50');
});

test('recalculation creates the entry when only imported data exists and deletes it when empty', function (): void {
    $category = Category::factory()->expense()->create();
    $transaction = confirmedTransaction($category, '2026-02-10', 42);

    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 2);

    expect(ActualEntry::sole()->amount)->toEqual('42.00')
        ->and(ActualEntry::sole()->manual_amount)->toEqual('0.00');

    $transaction->delete();
    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 2);

    expect(ActualEntry::count())->toBe(0);
});

test('refunds reduce the imported amount', function (): void {
    $category = Category::factory()->expense()->create();
    confirmedTransaction($category, '2026-02-10', 80);
    confirmedTransaction($category, '2026-02-12', -20);

    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 2);

    expect(ActualEntry::sole()->amount)->toEqual('60.00');
});

test('manual edits keep the imported part', function (): void {
    $category = Category::factory()->expense()->create();
    confirmedTransaction($category, '2026-03-05', 30);
    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 3);

    $this->post('/actual/bulk', [
        'year' => 2026,
        'month' => 3,
        'entries' => [['category_id' => $category->id, 'amount' => '70']],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $entry = ActualEntry::sole();
    expect($entry->manual_amount)->toEqual('70.00')
        ->and($entry->imported_amount)->toEqual('30.00')
        ->and($entry->amount)->toEqual('100.00');

    $this->post('/actual/bulk', [
        'year' => 2026,
        'month' => 3,
        'entries' => [['category_id' => $category->id, 'amount' => '0']],
    ])->assertRedirect();

    expect(ActualEntry::sole()->amount)->toEqual('30.00');
});

test('zero manual amount without imported data deletes the entry', function (): void {
    $category = Category::factory()->expense()->create();
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 50]);

    $this->post('/actual/bulk', [
        'year' => 2026,
        'month' => 3,
        'entries' => [['category_id' => $category->id, 'amount' => '0']],
    ])->assertRedirect();

    expect(ActualEntry::count())->toBe(0);
});

test('backup export and import preserve bank import data and the actual split', function (): void {
    $category = Category::factory()->expense()->create();
    $transaction = confirmedTransaction($category, '2026-03-05', 30);
    $transaction->update(['raw_description' => 'Pagamento, "POS" presso BAR']);
    ActualEntry::factory()->create(['category_id' => $category->id, 'year' => 2026, 'month' => 3, 'amount' => 100, 'manual_amount' => 70, 'imported_amount' => 30]);

    $zipContent = $this->get('/data/export')->streamedContent();
    $path = tempnam(sys_get_temp_dir(), 'varys_test_').'.zip';
    file_put_contents($path, $zipContent);

    $this->post('/data/import', ['file' => new UploadedFile($path, 'backup.zip', 'application/zip', null, true)])
        ->assertSessionHasNoErrors();

    unlink($path);

    expect(BankTransaction::sole()->raw_description)->toBe('Pagamento, "POS" presso BAR')
        ->and(BankTransaction::sole()->status)->toBe(TransactionStatus::Confirmed)
        ->and(ActualEntry::sole()->manual_amount)->toEqual('70.00')
        ->and(ActualEntry::sole()->imported_amount)->toEqual('30.00');
});
