<?php

use App\Enums\BankImportStatus;
use App\Enums\CategorizationSource;
use App\Enums\MerchantMatchType;
use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\BankCategoryMapping;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\MerchantRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Tests\Support\BankStatementFixtures;

uses(RefreshDatabase::class);

function uploadStatement(string $path, ?string $bank = null): TestResponse
{
    return test()->post('/bank-imports', array_filter([
        'file' => new UploadedFile($path, 'movimenti.xlsx', null, null, true),
        'bank' => $bank,
    ]));
}

function transactionFor(string $merchantKey): BankTransaction
{
    return BankTransaction::query()->where('merchant_key', $merchantKey)->firstOrFail();
}

test('uploading a statement stores every movement for review', function (): void {
    $response = uploadStatement(BankStatementFixtures::ing());

    $import = BankImport::sole();
    $response->assertRedirect("/bank-imports/{$import->id}");

    expect($import->status)->toBe(BankImportStatus::Review)
        ->and($import->rows_total)->toBe(8)
        ->and($import->rows_imported)->toBe(8)
        ->and($import->period_start?->toDateString())->toBe('2026-01-01')
        ->and($import->period_end?->toDateString())->toBe('2026-03-01')
        ->and(BankTransaction::query()->where('status', TransactionStatus::ToReview)->count())->toBe(8);

    $amazon = transactionFor('AMAZON');
    expect((float) $amazon->amount)->toBe(-29.99)
        ->and($amazon->accounting_date->toDateString())->toBe('2026-01-01')
        ->and($amazon->operation_date->toDateString())->toBe('2025-12-30');
});

test('uploading the same statement twice skips duplicates', function (): void {
    uploadStatement(BankStatementFixtures::ing());
    uploadStatement(BankStatementFixtures::ing());

    $second = BankImport::query()->latest('id')->first();

    expect($second->rows_imported)->toBe(0)
        ->and($second->rows_duplicates)->toBe(8)
        ->and(BankTransaction::count())->toBe(8);
});

test('unsupported files return a validation error', function (): void {
    uploadStatement(BankStatementFixtures::ing(), 'intesa')
        ->assertSessionHasErrors(['file' => 'Il file non sembra un export di Intesa Sanpaolo.']);

    expect(BankImport::count())->toBe(0);
});

test('merchant memory, keyword rules and exclusions categorize locally', function (): void {
    $groceries = Category::factory()->expense()->create();
    $phone = Category::factory()->expense()->create();
    MerchantRule::factory()->create(['pattern' => 'CARTA DI CREDITO ING', 'category_id' => $phone->id]);
    MerchantRule::factory()->keyword('IPER')->create(['category_id' => $groceries->id]);
    MerchantRule::factory()->create(['pattern' => 'PERSONA_2', 'category_id' => null, 'exclude' => true]);
    MerchantRule::factory()->create(['pattern' => 'AMAZON', 'category_id' => $groceries->id, 'always_ask' => true]);

    uploadStatement(BankStatementFixtures::ing());

    expect(transactionFor('CARTA DI CREDITO ING'))
        ->status->toBe(TransactionStatus::Auto)
        ->categorization_source->toBe(CategorizationSource::Memory)
        ->category_id->toBe($phone->id)
        ->and(transactionFor('IPER MAGENTA NCR'))
        ->status->toBe(TransactionStatus::Auto)
        ->categorization_source->toBe(CategorizationSource::Keyword)
        ->and(transactionFor('PERSONA_2')->status)->toBe(TransactionStatus::Excluded)
        ->and(transactionFor('AMAZON'))
        ->status->toBe(TransactionStatus::ToReview)
        ->category_id->toBe($groceries->id);
});

test('income categories are never applied to outgoing movements', function (): void {
    $income = Category::factory()->income()->create();
    MerchantRule::factory()->create(['pattern' => 'IPER MAGENTA NCR', 'category_id' => $income->id]);

    uploadStatement(BankStatementFixtures::ing());

    expect(transactionFor('IPER MAGENTA NCR'))
        ->status->toBe(TransactionStatus::ToReview)
        ->category_id->toBeNull();
});

test('bank categories are only a suggestion', function (): void {
    $health = Category::factory()->expense()->create();
    BankCategoryMapping::factory()->create(['bank_category' => 'Farmacia', 'category_id' => $health->id]);

    uploadStatement(BankStatementFixtures::intesa());

    expect(transactionFor('FARMACIA DELLA BASILI. MAGENTA'))
        ->status->toBe(TransactionStatus::ToReview)
        ->categorization_source->toBe(CategorizationSource::Bank)
        ->category_id->toBe($health->id);
});

test('assigning a category can apply to every movement of the same merchant', function (): void {
    $category = Category::factory()->expense()->create();
    uploadStatement(BankStatementFixtures::ing());
    $amazon = transactionFor('AMAZON');

    $this->patch("/bank-transactions/{$amazon->id}", ['category_id' => $category->id, 'exclude' => false, 'apply_to_merchant' => true])
        ->assertRedirect();

    expect(BankTransaction::query()->where('merchant_key', 'AMAZON')->pluck('status')->unique()->all())
        ->toBe([TransactionStatus::Auto])
        ->and(BankTransaction::query()->where('merchant_key', 'AMAZON')->where('category_id', $category->id)->count())->toBe(2);
});

test('completing an import requires every movement to be reviewed', function (): void {
    uploadStatement(BankStatementFixtures::ing());

    $this->post('/bank-imports/'.BankImport::sole()->id.'/complete')
        ->assertSessionHasErrors('import');

    expect(BankImport::sole()->status)->toBe(BankImportStatus::Review);
});

test('completing an import updates actual entries and remembers merchants', function (): void {
    $expense = Category::factory()->expense()->create();
    $income = Category::factory()->income()->create();
    uploadStatement(BankStatementFixtures::ing());
    $import = BankImport::sole();

    foreach (BankTransaction::all() as $transaction) {
        $isIncome = (float) $transaction->amount > 0;
        $this->patch("/bank-transactions/{$transaction->id}", [
            'category_id' => $isIncome ? $income->id : $expense->id,
            'exclude' => $transaction->merchant_key === 'LACOS GROUP SRL',
            'apply_to_merchant' => false,
        ]);
    }

    $this->post("/bank-imports/{$import->id}/complete")->assertRedirect('/actual?year=2026&month=3');

    expect($import->fresh()->status)->toBe(BankImportStatus::Completed)
        ->and(BankTransaction::query()->where('status', TransactionStatus::Confirmed)->count())->toBe(7)
        // January: Amazon 29.99 + RID 780.76 + IPER 116.49 + credit card 29.90
        ->and(ActualEntry::query()->where(['category_id' => $expense->id, 'month' => 1])->sole()->amount)->toEqual('957.14')
        ->and(ActualEntry::query()->where(['category_id' => $income->id, 'month' => 1])->sole()->amount)->toEqual('500.00')
        ->and(MerchantRule::query()->where('match_type', MerchantMatchType::Exact)->where('pattern', 'AMAZON')->sole()->category_id)->toBe($expense->id)
        ->and(MerchantRule::query()->where('pattern', 'LACOS GROUP SRL')->sole()->exclude)->toBeTrue();
});

test('deleting a completed import removes its amounts from actual entries', function (): void {
    $expense = Category::factory()->expense()->create();
    $income = Category::factory()->income()->create();
    uploadStatement(BankStatementFixtures::ing());
    $import = BankImport::sole();

    foreach (BankTransaction::all() as $transaction) {
        $this->patch("/bank-transactions/{$transaction->id}", [
            'category_id' => (float) $transaction->amount > 0 ? $income->id : $expense->id,
            'exclude' => false,
            'apply_to_merchant' => false,
        ]);
    }

    $this->post("/bank-imports/{$import->id}/complete");
    expect(ActualEntry::count())->toBeGreaterThan(0);

    $this->delete("/bank-imports/{$import->id}")->assertRedirect('/bank-imports');

    expect(BankTransaction::count())->toBe(0)
        ->and(ActualEntry::count())->toBe(0);
});

test('transactions of a completed import cannot be changed', function (): void {
    $import = BankImport::factory()->completed()->create();
    $transaction = BankTransaction::factory()->confirmed()->create(['bank_import_id' => $import->id]);

    $this->patch("/bank-transactions/{$transaction->id}", ['category_id' => null, 'exclude' => true, 'apply_to_merchant' => false])
        ->assertSessionHasErrors('transaction');
});

test('import pages render', function (): void {
    uploadStatement(BankStatementFixtures::intesa());
    $import = BankImport::sole();

    $this->get('/bank-imports')
        ->assertInertia(fn ($page) => $page
            ->component('bank-imports/index')
            ->has('imports', 1)
            ->where('imports.0.bank', 'Intesa Sanpaolo')
            ->has('banks', 2));

    $this->get("/bank-imports/{$import->id}")
        ->assertInertia(fn ($page) => $page
            ->component('bank-imports/show')
            ->where('bankImport.rows_imported', 10)
            ->has('transactions', 10)
            ->where('transactions.0.merchant_key', 'ZALANDO PAYMENTS'));
});
