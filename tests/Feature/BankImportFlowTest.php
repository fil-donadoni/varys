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

    expect(transactionFor('FARMACIA DELLA BASILI MAGENTA'))
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
            ->where('imports.0.bank', 'Intesa Sanpaolo'));

    $this->get("/bank-imports/{$import->id}")
        ->assertInertia(fn ($page) => $page
            ->component('bank-imports/show')
            ->where('bankImport.rows_imported', 10)
            ->has('transactions', 10)
            ->where('transactions.0.merchant_key', 'ZALANDO PAYMENTS'));
});

test('identifiers are masked before movements are stored', function (): void {
    $path = BankStatementFixtures::xlsx([
        ['Data', 'Operazione', 'Dettagli', 'Conto o carta', 'Contabilizzazione', 'Categoria', 'Valuta', 'Importo'],
        ['date:2026-03-24', 'Bar Roma', 'Pagamento Su POS BAR ROMA Carta N.5167 XXXX XXXX XX60 COD. 5465441/00001', 'Conto 1000/00012345', 'CONTABILIZZATO', 'Ristoranti e bar', 'EUR', -13],
        ['date:2026-03-25', 'Bonifico Disposto Da ACME SRL', 'Bonifico da IBAN IT60X0542811101000000123456 rif RSSMRA80A01H501U', 'Conto 1000/00012345', 'CONTABILIZZATO', 'Bonifici ricevuti', 'EUR', 100],
        ['date:2026-03-26', 'Paypal *shop', 'Paypal *shop', 'SUPERFLASH ****534207000006', 'CONTABILIZZATO', 'Altre uscite', 'EUR', -5],
    ]);

    uploadStatement($path);

    $descriptions = BankTransaction::query()->pluck('raw_description')->implode(' ');

    expect($descriptions)
        ->not->toContain('5167', 'IT60X', 'RSSMRA80', '534207')
        ->toContain('[CARTA]', '[IBAN]', '[CF]')
        ->and(BankTransaction::query()->where('merchant_key', 'PAYPAL *SHOP')->sole()->payment_instrument)->toBe('SUPERFLASH')
        ->and(BankImport::sole()->anonymization_stats)->toMatchArray(['cards' => 1, 'ibans' => 1, 'fiscal_codes' => 1]);
});

test('uploading an already imported file creates no import and points to the existing one', function (): void {
    uploadStatement(BankStatementFixtures::ing());
    $first = BankImport::sole();

    uploadStatement(BankStatementFixtures::ing())
        ->assertRedirect("/bank-imports/{$first->id}")
        ->assertSessionHas('notice');

    expect(BankImport::count())->toBe(1)
        ->and(BankTransaction::count())->toBe(8);
});

test('a completed import is never duplicated by a new upload', function (): void {
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
    $totals = ActualEntry::query()->orderBy('id')->pluck('amount')->all();

    uploadStatement(BankStatementFixtures::ing())->assertRedirect("/bank-imports/{$import->id}");

    expect(BankImport::count())->toBe(1)
        ->and(ActualEntry::query()->orderBy('id')->pluck('amount')->all())->toBe($totals);
});

test('an overlapping file only imports the new movements', function (): void {
    $header = ['DATA CONTABILE', 'DATA VALUTA', 'CAUSALE', 'DESCRIZIONE OPERAZIONE', 'IMPORTO IN EURO'];
    $coffee = fn (string $day): array => ["date:2026-01-{$day}", "date:2026-01-{$day}", 'Pagamento Carta', 'Operazione Mastercard del '.$day.'/01/2026 alle ore 10:00 presso BAR ROMA', -1.5];

    // Two identical coffees on the same day are two movements, not a duplicate.
    uploadStatement(BankStatementFixtures::xlsx([$header, $coffee('10'), $coffee('10')]));
    uploadStatement(BankStatementFixtures::xlsx([$header, $coffee('10'), $coffee('10'), $coffee('11')]));

    $second = BankImport::query()->latest('id')->first();

    expect(BankTransaction::count())->toBe(3)
        ->and($second->rows_imported)->toBe(1)
        ->and($second->rows_duplicates)->toBe(2);
});

test('a category can be created on the fly and assigned to the whole merchant', function (): void {
    uploadStatement(BankStatementFixtures::ing());
    Category::factory()->expense()->create(['sort_order' => 7]);
    $amazon = transactionFor('AMAZON');

    $this->post("/bank-transactions/{$amazon->id}/category", [
        'name' => ' Acquisti online ',
        'type' => 'expense',
        'color' => '#22c55e',
        'apply_to_merchant' => true,
    ])->assertRedirect()->assertSessionHasNoErrors()->assertSessionHas('notice');

    $category = Category::query()->where('name', 'Acquisti online')->sole();

    expect($category->sort_order)->toBe(8)
        ->and(BankTransaction::query()->where('merchant_key', 'AMAZON')->pluck('category_id')->unique()->all())->toBe([$category->id])
        ->and(BankTransaction::query()->where('merchant_key', 'AMAZON')->pluck('status')->unique()->all())->toBe([TransactionStatus::Auto]);
});

test('creating a category on the fly validates name and type', function (): void {
    uploadStatement(BankStatementFixtures::ing());
    Category::factory()->expense()->create(['name' => 'Spesa']);
    $amazon = transactionFor('AMAZON');

    $this->post("/bank-transactions/{$amazon->id}/category", ['name' => 'Spesa', 'type' => 'expense', 'apply_to_merchant' => true])
        ->assertSessionHasErrors(['name' => 'Esiste già una categoria con questo nome.']);

    $this->post("/bank-transactions/{$amazon->id}/category", ['name' => 'Stipendio', 'type' => 'income', 'apply_to_merchant' => true])
        ->assertSessionHasErrors('type');

    expect(Category::count())->toBe(1);
});

test('merchants remembered from another bank match even when written differently', function (): void {
    $health = Category::factory()->expense()->create();
    $food = Category::factory()->expense()->create();
    // Rules as the ING import saved them.
    MerchantRule::factory()->create(['pattern' => 'FARMACIA DELLA BASILI', 'category_id' => $health->id]);
    MerchantRule::factory()->create(['pattern' => 'ANTICO', 'category_id' => $food->id]);
    MerchantRule::factory()->create(['pattern' => "IPER STATION MAGENTA CORSO'", 'category_id' => $food->id]);

    uploadStatement(BankStatementFixtures::intesa());

    // Two or more words in common: applied.
    expect(transactionFor('FARMACIA DELLA BASILI MAGENTA'))
        ->status->toBe(TransactionStatus::Auto)
        ->categorization_source->toBe(CategorizationSource::SimilarMemory)
        ->category_id->toBe($health->id)
        // The remembered merchant can also be the longer one.
        ->and(transactionFor('IPER STATION MAGENTA'))
        ->status->toBe(TransactionStatus::Auto)
        ->category_id->toBe($food->id)
        // A single word in common is only proposed.
        ->and(transactionFor('ANTICO VINAIO ITALIA SRL'))
        ->status->toBe(TransactionStatus::ToReview)
        ->category_id->toBe($food->id);
});

test('confirming a proposed category keeps its origin', function (): void {
    $category = Category::factory()->expense()->create();
    $other = Category::factory()->expense()->create();
    uploadStatement(BankStatementFixtures::ing());
    BankTransaction::query()->where('merchant_key', 'AMAZON')->update([
        'category_id' => $category->id,
        'categorization_source' => CategorizationSource::Llm,
        'confidence' => 0.6,
        'status' => TransactionStatus::ToReview,
    ]);
    $amazon = transactionFor('AMAZON');

    $this->patch("/bank-transactions/{$amazon->id}", ['category_id' => $category->id, 'exclude' => false, 'apply_to_merchant' => true])
        ->assertSessionHasNoErrors();

    $rows = BankTransaction::query()->where('merchant_key', 'AMAZON')->get();
    expect($rows->pluck('status')->unique()->all())->toBe([TransactionStatus::Auto])
        ->and($rows->pluck('categorization_source')->unique()->all())->toBe([CategorizationSource::Llm])
        ->and((float) $rows->first()->confidence)->toBe(0.6);

    // Choosing a different category is a manual decision.
    $this->patch("/bank-transactions/{$amazon->id}", ['category_id' => $other->id, 'exclude' => false, 'apply_to_merchant' => true]);

    expect(BankTransaction::query()->where('merchant_key', 'AMAZON')->pluck('categorization_source')->unique()->all())
        ->toBe([CategorizationSource::Manual]);
});
