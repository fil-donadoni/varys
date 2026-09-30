<?php

use App\Enums\CategorizationSource;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Setting;
use App\Services\BankImport\Llm\CategorizationPrompt;
use App\Services\BankImport\Llm\CategoryOption;
use App\Services\BankImport\Llm\LlmPayloadBuilder;
use App\Services\BankImport\Llm\MerchantCategorizer;
use App\Services\BankImport\Llm\MerchantInput;
use App\Services\BankImport\Llm\NullMerchantCategorizer;
use App\Services\BankImport\Llm\OllamaMerchantCategorizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\Support\BankStatementFixtures;
use Tests\Support\FakeMerchantCategorizer;

uses(RefreshDatabase::class);

function importStatement(string $path): BankImport
{
    test()->post('/bank-imports', ['file' => new UploadedFile($path, 'movimenti.xlsx', null, null, true)]);

    return BankImport::query()->latest('id')->firstOrFail();
}

function fakeCategorizer(array $answers = []): FakeMerchantCategorizer
{
    $fake = new FakeMerchantCategorizer($answers);
    app()->instance(MerchantCategorizer::class, $fake);

    return $fake;
}

test('the payload only contains anonymized merchant names', function (): void {
    Setting::setValue('anonymize_masks', "Lacos\nRossi");
    $fake = fakeCategorizer();
    $import = importStatement(BankStatementFixtures::ing());

    $this->post("/bank-imports/{$import->id}/categorize")->assertSessionHasNoErrors();

    $sent = $fake->sentNames();
    $payload = CategorizationPrompt::userMessage($fake->requests[0], []);

    expect($sent)->toContain('AMAZON', 'IPER MAGENTA NCR', 'CARTA DI CREDITO ING', 'INTERESSI E COMPETENZE')
        // Transfer counterparties without a company form are people: never sent.
        ->not->toContain('PERSONA_2')
        // Custom masks from settings keep companies out too.
        ->not->toContain('LACOS GROUP SRL')
        ->and($payload)->not->toContain('29.99', '116.49', '2026', 'Mastercard');

    foreach ($fake->requests[0] as $input) {
        expect($input->id)->toMatch('/^[a-z0-9]{6}$/');
    }
});

test('the preview explains what is not sent', function (): void {
    Setting::setValue('anonymize_masks', 'Lacos');
    fakeCategorizer();
    $import = importStatement(BankStatementFixtures::ing());

    $preview = app(LlmPayloadBuilder::class)->preview($import);

    expect(collect($preview['skipped'])->pluck('reason', 'merchant_key')->all())->toBe([
        'LACOS GROUP SRL' => LlmPayloadBuilder::SKIP_PERSONAL_DATA,
        'PERSONA_2' => LlmPayloadBuilder::SKIP_PERSON,
    ])
        ->and(collect($preview['send'])->firstWhere('merchant_key', 'AMAZON'))
        ->toMatchArray(['direction' => 'uscita', 'count' => 2]);
});

test('suggestions become ready or to review depending on confidence', function (): void {
    $groceries = Category::factory()->expense()->create();
    $online = Category::factory()->expense()->create();
    $salary = Category::factory()->income()->create();
    fakeCategorizer([
        'IPER MAGENTA NCR' => [$groceries->id, 0.95],
        'AMAZON' => [$online->id, 0.9, true],
        'CARTA DI CREDITO ING' => [$online->id, 0.4],
        'RAPPORTI INTERNI SPORTELLO' => [$salary->id, 0.99],
    ]);
    $import = importStatement(BankStatementFixtures::ing());

    $this->post("/bank-imports/{$import->id}/categorize")->assertSessionHasNoErrors();

    $iper = BankTransaction::query()->where('merchant_key', 'IPER MAGENTA NCR')->sole();
    expect($iper)
        ->status->toBe(TransactionStatus::Auto)
        ->categorization_source->toBe(CategorizationSource::Llm)
        ->category_id->toBe($groceries->id)
        ->and((float) $iper->confidence)->toBe(0.95)
        // Ambiguous and low confidence suggestions still need the user.
        ->and(BankTransaction::query()->where('merchant_key', 'AMAZON')->pluck('status')->unique()->all())->toBe([TransactionStatus::ToReview])
        ->and(BankTransaction::query()->where('merchant_key', 'CARTA DI CREDITO ING')->sole()->status)->toBe(TransactionStatus::ToReview)
        // An income category is never applied to an outgoing movement.
        ->and(BankTransaction::query()->where('merchant_key', 'RAPPORTI INTERNI SPORTELLO')->sole()->category_id)->toBeNull()
        ->and($import->fresh()->llm_stats)->toMatchArray(['auto' => 1, 'to_review' => 2, 'provider' => 'Fake']);
});

test('merchants already sent are not sent again', function (): void {
    $fake = fakeCategorizer();
    $import = importStatement(BankStatementFixtures::ing());

    $this->post("/bank-imports/{$import->id}/categorize");
    $this->post("/bank-imports/{$import->id}/categorize");

    expect($fake->requests)->toHaveCount(1);
});

test('merchants excluded by the user are not sent', function (): void {
    $fake = fakeCategorizer();
    $import = importStatement(BankStatementFixtures::ing());

    $this->post("/bank-imports/{$import->id}/categorize", ['excluded' => ['AMAZON']]);

    expect($fake->sentNames())->not->toContain('AMAZON')->toContain('IPER MAGENTA NCR');
});

test('an unavailable categorizer returns an error', function (): void {
    app()->instance(MerchantCategorizer::class, new NullMerchantCategorizer);
    $import = importStatement(BankStatementFixtures::ing());

    $this->post("/bank-imports/{$import->id}/categorize")
        ->assertSessionHasErrors(['llm' => 'Categorizzazione AI disattivata (BANK_IMPORT_LLM=none).']);
});

test('ollama receives the schema and its answer is parsed', function (): void {
    $category = Category::factory()->expense()->create();
    Http::fake(['localhost:11434/api/chat' => Http::response([
        'message' => ['content' => json_encode(['results' => [
            ['id' => 'abc123', 'category_id' => $category->id, 'confidence' => 1.4, 'ambiguous' => false],
            ['id' => 'unknown', 'category_id' => $category->id, 'confidence' => 1, 'ambiguous' => false],
        ]])],
    ])]);

    $suggestions = (new OllamaMerchantCategorizer('http://localhost:11434', 'qwen3:8b'))->categorize(
        [new MerchantInput('abc123', 'IPER', 'uscita')],
        [new CategoryOption($category->id, $category->name, $category->type)],
    );

    expect($suggestions)->toHaveCount(1)
        ->and($suggestions[0]->categoryId)->toBe($category->id)
        ->and($suggestions[0]->confidence)->toBe(1.0);

    Http::assertSent(fn ($request) => $request['model'] === 'qwen3:8b'
        && $request['format']['properties']['results']['items']['properties']['category_id']['enum'] === [0, $category->id]);
});

test('unknown categories in the answer are ignored', function (): void {
    $suggestions = CategorizationPrompt::parse(
        json_encode(['results' => [['id' => 'a', 'category_id' => 999, 'confidence' => 0.9, 'ambiguous' => false]]]),
        [new MerchantInput('a', 'X', 'uscita')],
        [],
    );

    expect($suggestions[0]->categoryId)->toBeNull();
});

test('the review page exposes the pipeline steps', function (): void {
    fakeCategorizer();
    $import = importStatement(BankStatementFixtures::intesa());

    $this->get("/bank-imports/{$import->id}")
        ->assertInertia(fn ($page) => $page
            ->component('bank-imports/show')
            ->where('pipeline.llm.provider', 'Fake')
            ->where('pipeline.llm.unavailable_reason', null)
            ->has('pipeline.llm.preview.send')
            ->has('pipeline.kinds')
            ->has('pipeline.anonymization'));
});
