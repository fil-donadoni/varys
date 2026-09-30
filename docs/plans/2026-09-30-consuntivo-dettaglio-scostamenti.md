# Consuntivo: dettaglio voci e scostamenti — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consuntivo con categorie espandibili (movimenti banca + voci manuali modificabili) e vista annuale degli scostamenti budget/consuntivo per categoria.

**Architecture:** Nuova tabella `actual_items` per le voci manuali; `actual_entries` resta un aggregato ricalcolato da `ActualEntryRecalculator` (voci manuali + movimenti confermati). `ActualEntryController@index` fornisce sia il dettaglio del mese sia il report annuale (`ActualVarianceReport`). Frontend: pagina `actual/index.tsx` diventa un guscio con tab Mese/Anno, componenti in `resources/js/components/actual/`, logica colore in `resources/js/lib/actual-variance.ts`.

**Tech Stack:** Laravel 12, PHP 8.4, PostgreSQL, Pest 4, React 19, Inertia v2, Tailwind v4, shadcn/ui, Vitest, bun.

**Spec:** `docs/specs/2026-09-30-consuntivo-dettaglio-scostamenti.md`

## Global Constraints

- Tutto il testo UI in italiano.
- Importi formattati con `formatCurrency()` da `@/lib/utils` (separatore migliaia sempre presente, già in `main`).
- Form Request separate Store/Update in `app/Http/Requests/App/`, regole array-based.
- Model: `$guarded = ['id']`, metodo `casts()`, tipi di ritorno espliciti sulle relazioni. Factory per ogni nuovo model.
- Graffe su tutte le strutture di controllo, constructor property promotion, return type espliciti.
- Frontend usa URL letterali (`'/actual-items'`) come il resto delle pagine, niente `confirm()`/`alert()` del browser.
- Soglie colore: verde < −10%, neutro entro ±10% (inclusi), ambra (+10%, +30%], rosso > +30% o effettivo > 0 senza budget (per le entrate logica invertita; entrata senza budget = verde).
- Mesi chiusi: anno passato 1..12; anno corrente 1..(mese corrente − 1); anno futuro nessuno.
- Quality gate a fine di ogni task backend: `vendor/bin/pint --dirty --format agent`, `./vendor/bin/phpstan analyse --memory-limit=512M`, `php artisan test --compact`. Frontend: `bun check:all`, `bun test:run`.

## Review Focus

1. Voce manuale spostata di mese/categoria → entrambi gli aggregati `actual_entries` ricalcolati (Task 3 lo testa).
2. Movimento ricategorizzato su import già completato → vecchia e nuova categoria ricalcolate; uscita verso categoria entrata rifiutata (Task 4).
3. Backup vecchio senza `actual_items.csv` → voci ricostruite, totali invariati (Task 5).
4. Categoria con budget 0 e spese > 0 nel report annuale → `variance_pct` null, niente divisione per zero; cella rossa "senza budget" (Task 6, Task 8).
5. Anno futuro (0 mesi chiusi) → medie null, UI mostra "—" senza crash (Task 6, Task 10).

---

### Task 1: Tabella `actual_items`, model, factory, seeder, backfill

**Files:**

- Create: `database/migrations/2026_09_30_150000_create_actual_items_table.php`
- Create: `app/Models/ActualItem.php`
- Modify: `app/Models/Category.php` (relazione `actualItems()`)
- Create: `database/factories/ActualItemFactory.php`
- Create: `database/seeders/ActualItemSeeder.php`
- Create: `app/Services/ActualItemBackfill.php`
- Test: `tests/Feature/ActualItemBackfillTest.php`

**Interfaces:**

- Produces: `App\Models\ActualItem` (`category_id`, `date` cast `date:Y-m-d`, `description`, `amount` decimal:2), `Category::actualItems(): HasMany`, `ActualItem::factory()`, `App\Services\ActualItemBackfill::fromManualAmounts(): int` (crea voci dalle righe `actual_entries` con `manual_amount <> 0`, ritorna numero voci create).

- [ ] **Step 1: Test che fallisce**

```php
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
```

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualItemBackfillTest.php`
Expected: FAIL, `Class "App\Models\ActualItem" not found`.

- [ ] **Step 3: Migrazione**

```php
<?php

use App\Services\ActualItemBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actual_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 12, 2); // positive = raises the category actual (expense spent, income received)
            $table->timestamps();

            $table->index(['category_id', 'date']);
        });

        // Manual amounts were a single number per month: keep them as one item each.
        app(ActualItemBackfill::class)->fromManualAmounts();
    }

    public function down(): void
    {
        Schema::dropIfExists('actual_items');
    }
};
```

- [ ] **Step 4: Model, relazione, factory, seeder, service**

`app/Models/ActualItem.php`:

```php
<?php

namespace App\Models;

use Database\Factories\ActualItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Manual line of the actual (cash payments, expenses not in any bank statement).
 *
 * @property Carbon $date
 */
class ActualItem extends Model
{
    /** @use HasFactory<ActualItemFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
```

In `app/Models/Category.php`, dopo `actualEntries()`:

```php
    /**
     * @return HasMany<ActualItem, $this>
     */
    public function actualItems(): HasMany
    {
        return $this->hasMany(ActualItem::class);
    }
```

`database/factories/ActualItemFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActualItem>
 */
class ActualItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'category_id' => Category::factory(),
            'date' => now()->startOfMonth()->toDateString(),
            'description' => fake()->sentence(3),
            'amount' => fake()->randomFloat(2, 5, 300),
        ];
    }
}
```

`database/seeders/ActualItemSeeder.php` (demo, non chiamato da `DatabaseSeeder`):

```php
<?php

namespace Database\Seeders;

use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Demo manual items for the current month: php artisan db:seed --class=ActualItemSeeder
 */
class ActualItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Category::query()->inRandomOrder()->limit(3)->get() as $category) {
            ActualItem::factory()->count(2)->create(['category_id' => $category->id]);
        }
    }
}
```

`app/Services/ActualItemBackfill.php` (usa `DB::table` perché gira dentro una migrazione):

```php
<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Converts the old single manual amount of each actual entry into an actual item.
 * Used by the migration and when restoring backups made before actual items existed.
 */
class ActualItemBackfill
{
    public function fromManualAmounts(): int
    {
        $entries = DB::table('actual_entries')->where('manual_amount', '<>', 0)->orderBy('id')->get();
        $now = now();

        foreach ($entries as $entry) {
            DB::table('actual_items')->insert([
                'category_id' => $entry->category_id,
                'date' => sprintf('%04d-%02d-01', $entry->year, $entry->month),
                'description' => ($entry->description ?? '') !== '' ? $entry->description : 'Voce manuale',
                'amount' => $entry->manual_amount,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $entries->count();
    }
}
```

- [ ] **Step 5: Migra e verifica test**

Run: `php artisan migrate && php artisan test --compact tests/Feature/ActualItemBackfillTest.php`
Expected: PASS (2 test).

- [ ] **Step 6: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M
git add database/migrations/2026_09_30_150000_create_actual_items_table.php app/Models/ActualItem.php app/Models/Category.php database/factories/ActualItemFactory.php database/seeders/ActualItemSeeder.php app/Services/ActualItemBackfill.php tests/Feature/ActualItemBackfillTest.php
git commit -m "Add actual items table with backfill from manual amounts"
```

---

### Task 2: Recalculator somma le voci manuali; rimozione `actual/bulk`

**Files:**

- Modify: `app/Services/BankImport/ActualEntryRecalculator.php`
- Modify: `app/Http/Controllers/App/ActualEntryController.php` (rimuovi `bulkUpsert`)
- Modify: `routes/web.php` (rimuovi rotta `actual.bulk-upsert`)
- Delete: `app/Http/Requests/App/BulkUpsertActualRequest.php`
- Modify: `tests/Feature/ActualEntrySplitTest.php`

**Interfaces:**

- Consumes: `ActualItem` (Task 1).
- Produces: `ActualEntryRecalculator::recalculate(int $categoryId, int $year, int $month): void` ora calcola `manual_amount` = somma `actual_items` con `date` nel mese. Firma invariata.

- [ ] **Step 1: Aggiorna test esistenti (falliscono)**

In `tests/Feature/ActualEntrySplitTest.php`:

- aggiungi `use App\Models\ActualItem;`
- sostituisci il test `recalculation sums confirmed transactions of the month on top of the manual amount` con:

```php
test('recalculation sums manual items and confirmed transactions of the month', function (): void {
    $category = Category::factory()->expense()->create();
    ActualItem::factory()->create(['category_id' => $category->id, 'date' => '2026-03-02', 'amount' => 60]);
    ActualItem::factory()->create(['category_id' => $category->id, 'date' => '2026-03-31', 'amount' => 40]);
    ActualItem::factory()->create(['category_id' => $category->id, 'date' => '2026-04-01', 'amount' => 999]);

    confirmedTransaction($category, '2026-03-01', 20.5);
    confirmedTransaction($category, '2026-03-31', 10);
    confirmedTransaction($category, '2026-04-01', 99);
    BankTransaction::factory()->create(['category_id' => $category->id, 'accounting_date' => '2026-03-10', 'amount' => -50, 'status' => TransactionStatus::ToReview]);

    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 3);

    $entry = ActualEntry::sole();
    expect($entry->manual_amount)->toEqual('100.00')
        ->and($entry->imported_amount)->toEqual('30.50')
        ->and($entry->amount)->toEqual('130.50');
});
```

- elimina i test `manual edits keep the imported part` e `zero manual amount without imported data deletes the entry` (coprivano `/actual/bulk`, sostituiti in Task 3).

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualEntrySplitTest.php`
Expected: FAIL su `manual_amount` (`0.00` invece di `100.00`).

- [ ] **Step 3: Implementa**

In `ActualEntryRecalculator::recalculate()` sostituisci la lettura di `$manual` (e aggiungi `use App\Models\ActualItem;`):

```php
        $manual = (float) ActualItem::query()
            ->where('category_id', $categoryId)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->sum('amount');
```

e prima del `save()`:

```php
        $entry->manual_amount = round($manual, 2);
```

Aggiorna il docblock di classe: `Keeps actual_entries in sync: manual_amount = sum of actual items, imported_amount = sum of confirmed bank transactions, amount = both.`

Rimuovi `bulkUpsert()` (e gli `use` non più usati: `BulkUpsertActualRequest`, `ActualEntryRecalculator`, `RedirectResponse`) da `ActualEntryController`, la riga `Route::post('actual/bulk', ...)` da `routes/web.php` e il file `BulkUpsertActualRequest.php`.

- [ ] **Step 4: Verifica**

Run: `php artisan test --compact`
Expected: PASS. (La pagina `actual/index.tsx` chiama ancora `/actual/bulk`: verrà riscritta in Task 11; nel frattempo il salvataggio da UI dà 405, accettabile tra task.)

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M
git add -A app/Services/BankImport/ActualEntryRecalculator.php app/Http/Controllers/App/ActualEntryController.php routes/web.php app/Http/Requests/App/BulkUpsertActualRequest.php tests/Feature/ActualEntrySplitTest.php
git commit -m "Compute manual actual amounts from actual items"
```

---

### Task 3: CRUD voci manuali

**Files:**

- Create: `app/Http/Controllers/App/ActualItemController.php`
- Create: `app/Http/Requests/App/StoreActualItemRequest.php`
- Create: `app/Http/Requests/App/UpdateActualItemRequest.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/ActualItemTest.php`

**Interfaces:**

- Consumes: `ActualItem`, `ActualEntryRecalculator::recalculate()`.
- Produces: `POST /actual-items`, `PUT /actual-items/{actual_item}`, `DELETE /actual-items/{actual_item}`. Payload store/update: `{category_id: int, date: 'Y-m-d', description: string, amount: numeric≠0}`. Risposta: redirect back con flash `success`.

- [ ] **Step 1: Test che falliscono**

```php
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

    $this->post('/actual-items', ['category_id' => $category->id, 'date' => 'ieri', 'description' => '', 'amount' => '0'])
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
```

Nota: la prima asserzione del primo test verifica che `60,50` venga rifiutato (`numeric`): il frontend deve inviare il punto decimale (Task 9 usa `parseAmount`).

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualItemTest.php`
Expected: FAIL, 404 su `/actual-items`.

- [ ] **Step 3: Request**

`StoreActualItemRequest.php` (e `UpdateActualItemRequest.php` identica con nome classe diverso):

```php
<?php

namespace App\Http\Requests\App;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreActualItemRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'between:-9999999999,9999999999', function (string $attribute, mixed $value, Closure $fail): void {
                if (is_numeric($value) && (float) $value === 0.0) {
                    $fail("L'importo non può essere zero.");
                }
            }],
        ];
    }
}
```

- [ ] **Step 4: Controller e rotte**

```php
<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreActualItemRequest;
use App\Http\Requests\App\UpdateActualItemRequest;
use App\Models\ActualItem;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ActualItemController extends Controller
{
    public function __construct(private readonly ActualEntryRecalculator $recalculator) {}

    public function store(StoreActualItemRequest $request): RedirectResponse
    {
        $item = DB::transaction(function () use ($request): ActualItem {
            $item = ActualItem::query()->create($request->validated());
            $this->recalculateFor($item);

            return $item;
        });

        return back()->with('success', "Voce \"{$item->description}\" aggiunta.");
    }

    public function update(UpdateActualItemRequest $request, ActualItem $actualItem): RedirectResponse
    {
        DB::transaction(function () use ($request, $actualItem): void {
            $previous = clone $actualItem;
            $actualItem->update($request->validated());

            $this->recalculateFor($previous);
            $this->recalculateFor($actualItem);
        });

        return back()->with('success', 'Voce aggiornata.');
    }

    public function destroy(ActualItem $actualItem): RedirectResponse
    {
        DB::transaction(function () use ($actualItem): void {
            $actualItem->delete();
            $this->recalculateFor($actualItem);
        });

        return back()->with('success', 'Voce eliminata.');
    }

    private function recalculateFor(ActualItem $item): void
    {
        $this->recalculator->recalculate($item->category_id, $item->date->year, $item->date->month);
    }
}
```

In `routes/web.php`, dopo la rotta `actual.index`:

```php
Route::resource('actual-items', ActualItemController::class)->only(['store', 'update', 'destroy']);
```

(+ `use App\Http\Controllers\App\ActualItemController;`)

- [ ] **Step 5: Verifica**

Run: `php artisan test --compact tests/Feature/ActualItemTest.php`
Expected: PASS (5 test).

- [ ] **Step 6: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M && php artisan test --compact
git add app/Http/Controllers/App/ActualItemController.php app/Http/Requests/App/StoreActualItemRequest.php app/Http/Requests/App/UpdateActualItemRequest.php routes/web.php tests/Feature/ActualItemTest.php
git commit -m "Add create, update and delete of manual actual items"
```

---

### Task 4: Ricategorizza/escludi movimenti confermati

**Files:**

- Create: `app/Http/Requests/App/ReassignBankTransactionRequest.php`
- Modify: `app/Http/Controllers/App/BankTransactionController.php` (metodo `reassign`)
- Modify: `routes/web.php`
- Test: `tests/Feature/BankTransactionReassignTest.php`

**Interfaces:**

- Consumes: `ActualEntryRecalculator::recalculate()`.
- Produces: `PATCH /bank-transactions/{bank_transaction}/reassign` payload `{category_id: int|null, exclude: bool}`. Errori di sessione sotto chiave `transaction`.

- [ ] **Step 1: Test che falliscono**

```php
<?php

use App\Enums\BankImportStatus;
use App\Enums\CategorizationSource;
use App\Enums\TransactionStatus;
use App\Models\ActualEntry;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function completedTransaction(Category $category, float $amount): BankTransaction
{
    $import = BankImport::factory()->create(['status' => BankImportStatus::Completed]);
    $transaction = BankTransaction::factory()->confirmed()->create([
        'bank_import_id' => $import->id,
        'category_id' => $category->id,
        'accounting_date' => '2026-08-12',
        'amount' => $amount,
    ]);
    app(ActualEntryRecalculator::class)->recalculate($category->id, 2026, 8);

    return $transaction;
}

function actualOf(Category $category): ?string
{
    return ActualEntry::query()->where(['category_id' => $category->id, 'year' => 2026, 'month' => 8])->value('amount');
}

test('a confirmed transaction of a completed import can move to another category', function (): void {
    $dining = Category::factory()->expense()->create();
    $groceries = Category::factory()->expense()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => $groceries->id, 'exclude' => false])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect($transaction->fresh()?->category_id)->toBe($groceries->id)
        ->and($transaction->fresh()?->status)->toBe(TransactionStatus::Confirmed)
        ->and($transaction->fresh()?->categorization_source)->toBe(CategorizationSource::Manual)
        ->and(actualOf($dining))->toBeNull()
        ->and(actualOf($groceries))->toEqual('45.00');
});

test('excluding a confirmed transaction removes it from the actual', function (): void {
    $dining = Category::factory()->expense()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => null, 'exclude' => true])
        ->assertSessionHasNoErrors();

    expect($transaction->fresh()?->status)->toBe(TransactionStatus::Excluded)
        ->and($transaction->fresh()?->category_id)->toBeNull()
        ->and(actualOf($dining))->toBeNull();
});

test('an outgoing movement cannot go into an income category', function (): void {
    $dining = Category::factory()->expense()->create();
    $salary = Category::factory()->income()->create();
    $transaction = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$transaction->id}/reassign", ['category_id' => $salary->id, 'exclude' => false])
        ->assertSessionHasErrors('transaction');

    expect($transaction->fresh()?->category_id)->toBe($dining->id);
});

test('only confirmed transactions can be reassigned and a target is required', function (): void {
    $dining = Category::factory()->expense()->create();
    $pending = BankTransaction::factory()->create(['category_id' => $dining->id, 'status' => TransactionStatus::Auto]);
    $confirmed = completedTransaction($dining, -45);

    $this->patch("/bank-transactions/{$pending->id}/reassign", ['category_id' => $dining->id, 'exclude' => false])
        ->assertSessionHasErrors('transaction');

    $this->patch("/bank-transactions/{$confirmed->id}/reassign", ['category_id' => null, 'exclude' => false])
        ->assertSessionHasErrors('category_id');
});
```

Verifica che `BankImport::factory()` accetti `status` (guarda `database/factories/BankImportFactory.php`; se lo stato completato ha già uno state method usa quello).

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/BankTransactionReassignTest.php`
Expected: FAIL, 404/405.

- [ ] **Step 3: Request**

```php
<?php

namespace App\Http\Requests\App;

use Illuminate\Foundation\Http\FormRequest;

class ReassignBankTransactionRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category_id' => ['nullable', 'required_unless:exclude,true', 'integer', 'exists:categories,id'],
            'exclude' => ['required', 'boolean'],
        ];
    }
}
```

Se `required_unless:exclude,true` non matcha il booleano `false` inviato come JSON, usa `Rule::requiredIf(fn (): bool => ! $this->boolean('exclude'))`.

- [ ] **Step 4: Controller e rotta**

In `BankTransactionController` (inietta il recalculator nel metodo):

```php
    /**
     * Fixes an already confirmed movement from the actual page: other category or excluded.
     */
    public function reassign(ReassignBankTransactionRequest $request, BankTransaction $bankTransaction, ActualEntryRecalculator $recalculator): RedirectResponse
    {
        if ($bankTransaction->status !== TransactionStatus::Confirmed) {
            return back()->withErrors(['transaction' => 'Si possono modificare qui solo i movimenti confermati.']);
        }

        $exclude = $request->boolean('exclude');
        $categoryId = $exclude ? null : $request->integer('category_id');

        if ($categoryId !== null && (float) $bankTransaction->amount < 0 && Category::query()->whereKey($categoryId)->value('type') === CategoryType::Income) {
            return back()->withErrors(['transaction' => 'Un\'uscita può andare solo in una categoria di spesa.']);
        }

        $previousCategoryId = $bankTransaction->category_id;

        DB::transaction(function () use ($bankTransaction, $exclude, $categoryId, $previousCategoryId, $recalculator): void {
            $bankTransaction->update([
                'category_id' => $categoryId,
                'categorization_source' => CategorizationSource::Manual,
                'confidence' => null,
                'status' => $exclude ? TransactionStatus::Excluded : TransactionStatus::Confirmed,
            ]);

            $date = $bankTransaction->accounting_date;
            foreach (array_unique(array_filter([$previousCategoryId, $categoryId])) as $affected) {
                $recalculator->recalculate($affected, $date->year, $date->month);
            }
        });

        return back()->with('success', $exclude ? 'Movimento escluso.' : 'Movimento spostato.');
    }
```

`use` da aggiungere: `App\Enums\CategorizationSource`, `App\Enums\TransactionStatus`, `App\Http\Requests\App\ReassignBankTransactionRequest`, `App\Services\BankImport\ActualEntryRecalculator`, `Illuminate\Support\Facades\DB`.

Rotta in `routes/web.php`:

```php
Route::patch('bank-transactions/{bank_transaction}/reassign', [BankTransactionController::class, 'reassign'])->name('bank-transactions.reassign');
```

- [ ] **Step 5: Verifica**

Run: `php artisan test --compact tests/Feature/BankTransactionReassignTest.php`
Expected: PASS (4 test).

- [ ] **Step 6: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M && php artisan test --compact
git add app/Http/Requests/App/ReassignBankTransactionRequest.php app/Http/Controllers/App/BankTransactionController.php routes/web.php tests/Feature/BankTransactionReassignTest.php
git commit -m "Allow reassigning or excluding confirmed bank transactions"
```

---

### Task 5: Backup con `actual_items`

**Files:**

- Modify: `app/Http/Controllers/App/DataExportController.php`
- Test: `tests/Feature/ActualItemBackupTest.php`

**Interfaces:**

- Consumes: `ActualItem`, `ActualItemBackfill::fromManualAmounts()`.
- Produces: file `actual_items.csv` nello ZIP (`id, category_id, date, description, amount`).

- [ ] **Step 1: Test che falliscono**

```php
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
```

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualItemBackupTest.php`
Expected: FAIL (`ActualItem::sole()` non trova righe / `deleteName` fallisce perché il file non esiste).

- [ ] **Step 3: Implementa**

Export, subito dopo `actual_entries.csv`:

```php
            $zip->addFromString('actual_items.csv', $this->buildCsv(
                ['id', 'category_id', 'date', 'description', 'amount'],
                $this->collectRows(ActualItem::query()->orderBy('id')->get(), fn (ActualItem $i): array => [
                    (string) $i->id,
                    (string) $i->category_id,
                    $i->date->toDateString(),
                    $i->description,
                    (string) $i->amount,
                ]),
            ));
```

Import: nella lista di cancellazione, prima di `ActualEntry::query()->delete();` aggiungi `ActualItem::query()->delete();`. Dopo il blocco `// Import actual entries`:

```php
                // Import actual items; backups made before they existed only have the manual amount.
                if ($zip->getFromName('actual_items.csv') !== false) {
                    $this->insertRows($zip, 'actual_items', fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'category_id' => (int) $row['category_id'],
                        'date' => $row['date'],
                        'description' => $row['description'],
                        'amount' => $row['amount'],
                    ]);
                } else {
                    app(ActualItemBackfill::class)->fromManualAmounts();
                }
```

Nel blocco `resetSequence`: `$this->resetSequence('actual_items');` dopo `actual_entries`. `use App\Models\ActualItem; use App\Services\ActualItemBackfill;`.

- [ ] **Step 4: Verifica**

Run: `php artisan test --compact tests/Feature/ActualItemBackupTest.php tests/Feature/ActualEntrySplitTest.php tests/Feature/BudgetEntryItemsTest.php`
Expected: PASS.

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M
git add app/Http/Controllers/App/DataExportController.php tests/Feature/ActualItemBackupTest.php
git commit -m "Include actual items in backup export and import"
```

---

### Task 6: `ActualVarianceReport`

**Files:**

- Create: `app/Services/ActualVarianceReport.php`
- Test: `tests/Feature/ActualVarianceReportTest.php`

**Interfaces:**

- Consumes: `Category`, `BudgetEntry`, `ActualEntry`.
- Produces: `ActualVarianceReport::build(int $year, CarbonImmutable $today): array` con forma:

```
[
  'closed_months' => int,
  'rows' => list<Row>,          // una per categoria, ordinate type poi sort_order
  'totals' => ['income' => Row, 'expense' => Row, 'net' => Row],
]
Row = [
  'category_id' => int|null,
  'type' => 'income'|'expense',   // net usa 'income' (più alto = meglio)
  'months' => list<['month' => int, 'budget' => float, 'actual' => float, 'status' => 'closed'|'current'|'future']>,
  'avg_budget' => float|null, 'avg_actual' => float|null,
  'variance' => float|null, 'variance_pct' => float|null,
  'peak' => ['month' => int, 'actual' => float]|null,
]
```

`variance_pct` è frazione (0.4 = +40%), null se `avg_budget` = 0 o null. `peak` = mese chiuso con `actual` massimo > 0 (null per `net`). Importi arrotondati a 2 decimali, pct a 4.

- [ ] **Step 1: Test che falliscono**

```php
<?php

use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Services\ActualVarianceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<int, float>  $budget  month => amount
 * @param  array<int, float>  $actual  month => amount
 */
function seedMonths(Category $category, int $year, array $budget, array $actual): void
{
    foreach ($budget as $month => $amount) {
        BudgetEntry::factory()->create(['category_id' => $category->id, 'year' => $year, 'month' => $month, 'amount' => $amount]);
    }
    foreach ($actual as $month => $amount) {
        ActualEntry::factory()->create(['category_id' => $category->id, 'year' => $year, 'month' => $month, 'amount' => $amount]);
    }
}

/**
 * @return array<string, mixed>
 */
function rowFor(array $report, Category $category): array
{
    return collect($report['rows'])->firstWhere('category_id', $category->id);
}

test('current year averages only closed months and finds the peak', function (): void {
    $dining = Category::factory()->expense()->create();
    seedMonths($dining, 2026, array_fill(1, 12, 300.0), [1 => 300, 2 => 350, 8 => 580, 9 => 900]);

    $report = app(ActualVarianceReport::class)->build(2026, CarbonImmutable::parse('2026-09-30'));
    $row = rowFor($report, $dining);

    expect($report['closed_months'])->toBe(8)
        ->and($row['avg_budget'])->toBe(300.0)
        ->and($row['avg_actual'])->toBe(153.75)          // (300+350+580)/8, September excluded
        ->and($row['variance'])->toBe(-146.25)
        ->and($row['variance_pct'])->toBe(-0.4875)
        ->and($row['peak'])->toBe(['month' => 8, 'actual' => 580.0])
        ->and($row['months'][8]['status'])->toBe('current')
        ->and($row['months'][9]['status'])->toBe('future')
        ->and($row['months'][7])->toBe(['month' => 8, 'budget' => 300.0, 'actual' => 580.0, 'status' => 'closed']);
});

test('past years use all twelve months, future years have no averages', function (): void {
    $dining = Category::factory()->expense()->create();
    seedMonths($dining, 2025, [1 => 100], [1 => 1200]);
    seedMonths($dining, 2027, [1 => 100], []);

    $past = rowFor(app(ActualVarianceReport::class)->build(2025, CarbonImmutable::parse('2026-09-30')), $dining);
    $future = app(ActualVarianceReport::class)->build(2027, CarbonImmutable::parse('2026-09-30'));

    expect($past['avg_actual'])->toBe(100.0)
        ->and($past['avg_budget'])->toBe(8.33)
        ->and($future['closed_months'])->toBe(0)
        ->and(rowFor($future, $dining)['avg_actual'])->toBeNull()
        ->and(rowFor($future, $dining)['variance_pct'])->toBeNull()
        ->and(rowFor($future, $dining)['peak'])->toBeNull();
});

test('zero budget gives no percentage and totals combine categories', function (): void {
    $gifts = Category::factory()->expense()->create();
    $salary = Category::factory()->income()->create();
    seedMonths($gifts, 2025, [], [3 => 120]);
    seedMonths($salary, 2025, array_fill(1, 12, 2000.0), array_fill(1, 12, 2100.0));

    $report = app(ActualVarianceReport::class)->build(2025, CarbonImmutable::parse('2026-09-30'));

    expect(rowFor($report, $gifts)['variance'])->toBe(10.0)
        ->and(rowFor($report, $gifts)['variance_pct'])->toBeNull()
        ->and($report['totals']['income']['avg_actual'])->toBe(2100.0)
        ->and($report['totals']['expense']['avg_actual'])->toBe(10.0)
        ->and($report['totals']['net']['avg_actual'])->toBe(2090.0)
        ->and($report['totals']['net']['avg_budget'])->toBe(2000.0)
        ->and($report['totals']['net']['peak'])->toBeNull();
});
```

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualVarianceReportTest.php`
Expected: FAIL, classe non trovata.

- [ ] **Step 3: Implementa**

```php
<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use Carbon\CarbonImmutable;

/**
 * Budget vs actual per category across a year. Averages cover only closed months,
 * so a half-spent current month doesn't pull the average down.
 *
 * @phpstan-type MonthCell array{month: int, budget: float, actual: float, status: 'closed'|'current'|'future'}
 * @phpstan-type Row array{category_id: int|null, type: string, months: list<MonthCell>, avg_budget: float|null, avg_actual: float|null, variance: float|null, variance_pct: float|null, peak: array{month: int, actual: float}|null}
 */
class ActualVarianceReport
{
    /**
     * @return array{closed_months: int, rows: list<Row>, totals: array{income: Row, expense: Row, net: Row}}
     */
    public function build(int $year, CarbonImmutable $today): array
    {
        $closedMonths = match (true) {
            $year < $today->year => 12,
            $year === $today->year => $today->month - 1,
            default => 0,
        };

        $categories = Category::query()->orderBy('type')->orderBy('sort_order')->get();
        $budget = $this->amountsByCategoryAndMonth(BudgetEntry::query()->where('year', $year)->get(['category_id', 'month', 'amount']));
        $actual = $this->amountsByCategoryAndMonth(ActualEntry::query()->where('year', $year)->get(['category_id', 'month', 'amount']));

        $rows = [];
        $sums = ['income' => ['budget' => array_fill(1, 12, 0.0), 'actual' => array_fill(1, 12, 0.0)], 'expense' => ['budget' => array_fill(1, 12, 0.0), 'actual' => array_fill(1, 12, 0.0)]];

        foreach ($categories as $category) {
            /** @var CategoryType $type */
            $type = $category->type;
            $categoryBudget = $budget[$category->id] ?? [];
            $categoryActual = $actual[$category->id] ?? [];

            for ($month = 1; $month <= 12; $month++) {
                $sums[$type->value]['budget'][$month] += $categoryBudget[$month] ?? 0.0;
                $sums[$type->value]['actual'][$month] += $categoryActual[$month] ?? 0.0;
            }

            $rows[] = $this->row($category->id, $type->value, $categoryBudget, $categoryActual, $year, $today, $closedMonths, true);
        }

        $netBudget = [];
        $netActual = [];
        for ($month = 1; $month <= 12; $month++) {
            $netBudget[$month] = $sums['income']['budget'][$month] - $sums['expense']['budget'][$month];
            $netActual[$month] = $sums['income']['actual'][$month] - $sums['expense']['actual'][$month];
        }

        return [
            'closed_months' => $closedMonths,
            'rows' => $rows,
            'totals' => [
                'income' => $this->row(null, 'income', $sums['income']['budget'], $sums['income']['actual'], $year, $today, $closedMonths, true),
                'expense' => $this->row(null, 'expense', $sums['expense']['budget'], $sums['expense']['actual'], $year, $today, $closedMonths, true),
                'net' => $this->row(null, 'income', $netBudget, $netActual, $year, $today, $closedMonths, false),
            ],
        ];
    }

    /**
     * @param  iterable<BudgetEntry|ActualEntry>  $entries
     * @return array<int, array<int, float>>
     */
    private function amountsByCategoryAndMonth(iterable $entries): array
    {
        $amounts = [];
        foreach ($entries as $entry) {
            $amounts[$entry->category_id][$entry->month] = ($amounts[$entry->category_id][$entry->month] ?? 0.0) + (float) $entry->amount;
        }

        return $amounts;
    }

    /**
     * @param  array<int, float>  $budget
     * @param  array<int, float>  $actual
     * @return Row
     */
    private function row(?int $categoryId, string $type, array $budget, array $actual, int $year, CarbonImmutable $today, int $closedMonths, bool $withPeak): array
    {
        $months = [];
        $peak = null;
        $budgetSum = 0.0;
        $actualSum = 0.0;

        for ($month = 1; $month <= 12; $month++) {
            $b = round($budget[$month] ?? 0.0, 2);
            $a = round($actual[$month] ?? 0.0, 2);
            $status = match (true) {
                $month <= $closedMonths => 'closed',
                $year === $today->year && $month === $today->month => 'current',
                default => 'future',
            };

            if ($status === 'closed') {
                $budgetSum += $b;
                $actualSum += $a;
                if ($withPeak && $a > 0 && ($peak === null || $a > $peak['actual'])) {
                    $peak = ['month' => $month, 'actual' => $a];
                }
            }

            $months[] = ['month' => $month, 'budget' => $b, 'actual' => $a, 'status' => $status];
        }

        $avgBudget = $closedMonths > 0 ? round($budgetSum / $closedMonths, 2) : null;
        $avgActual = $closedMonths > 0 ? round($actualSum / $closedMonths, 2) : null;
        $variance = $avgBudget !== null && $avgActual !== null ? round($avgActual - $avgBudget, 2) : null;

        return [
            'category_id' => $categoryId,
            'type' => $type,
            'months' => $months,
            'avg_budget' => $avgBudget,
            'avg_actual' => $avgActual,
            'variance' => $variance,
            'variance_pct' => $variance !== null && $avgBudget !== null && $avgBudget != 0.0 ? round($variance / $avgBudget, 4) : null,
            'peak' => $peak,
        ];
    }
}
```

Nota: nel primo test `avg_actual` vale 153.75 perché il report usa i mesi chiusi (8): gen 300 + feb 350 + ago 580 = 1230 / 8.

- [ ] **Step 4: Verifica**

Run: `php artisan test --compact tests/Feature/ActualVarianceReportTest.php`
Expected: PASS (3 test).

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M
git add app/Services/ActualVarianceReport.php tests/Feature/ActualVarianceReportTest.php
git commit -m "Add yearly budget vs actual variance report"
```

---

### Task 7: Props della pagina consuntivo

**Files:**

- Modify: `app/Http/Controllers/App/ActualEntryController.php`
- Test: `tests/Feature/ActualIndexTest.php`

**Interfaces:**

- Consumes: `ActualVarianceReport::build()`, `ActualItem`, `BankTransaction`.
- Produces: props Inertia per `actual/index`:

```
tab: 'month'|'year'
year: int, month: int, expandedCategoryId: int|null
categories: list<{id, name, type, color, sort_order}>
budgets: Record<categoryId, number>            // budget del mese selezionato
lines: Record<categoryId, list<Line>>           // voci del mese selezionato
report: (vedi Task 6)
Line = { key: string ('bank-12' | 'manual-5'), kind: 'bank'|'manual', id: int, date: 'Y-m-d',
         description: string, amount: number (segno per tipo categoria: spesa positiva),
         bank_amount: number|null (segno banca, solo kind=bank), kind_label: string|null }
```

- [ ] **Step 1: Test che fallisce**

```php
<?php

use App\Enums\TransactionStatus;
use App\Models\ActualItem;
use App\Models\BankTransaction;
use App\Models\BudgetEntry;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('month tab lists manual items and confirmed movements per category', function (): void {
    $dining = Category::factory()->expense()->create();
    BudgetEntry::factory()->create(['category_id' => $dining->id, 'year' => 2026, 'month' => 8, 'amount' => 300]);
    $item = ActualItem::factory()->create(['category_id' => $dining->id, 'date' => '2026-08-15', 'description' => 'Cena amici', 'amount' => 60]);
    $movement = BankTransaction::factory()->confirmed()->create(['category_id' => $dining->id, 'accounting_date' => '2026-08-12', 'amount' => -45, 'merchant_label' => 'TRATTORIA X']);
    BankTransaction::factory()->create(['category_id' => $dining->id, 'accounting_date' => '2026-08-13', 'amount' => -99, 'status' => TransactionStatus::Auto]);
    ActualItem::factory()->create(['category_id' => $dining->id, 'date' => '2026-09-01']);

    $this->get("/actual?year=2026&month=8&category={$dining->id}")
        ->assertInertia(fn ($page) => $page
            ->component('actual/index')
            ->where('tab', 'month')
            ->where('expandedCategoryId', $dining->id)
            ->where("budgets.{$dining->id}", 300.0)
            ->has("lines.{$dining->id}", 2)
            ->where("lines.{$dining->id}.0.key", "bank-{$movement->id}")
            ->where("lines.{$dining->id}.0.description", 'TRATTORIA X')
            ->where("lines.{$dining->id}.0.amount", 45.0)
            ->where("lines.{$dining->id}.0.bank_amount", -45.0)
            ->where("lines.{$dining->id}.1.key", "manual-{$item->id}")
            ->where("lines.{$dining->id}.1.date", '2026-08-15')
            ->where("lines.{$dining->id}.1.amount", 60.0)
            ->has('report.rows', 1));
});

test('year tab is selected from the query string', function (): void {
    $this->get('/actual?tab=year&year=2025')
        ->assertInertia(fn ($page) => $page->where('tab', 'year')->where('year', 2025)->where('report.closed_months', 12));
});
```

- [ ] **Step 2: Verifica fallimento**

Run: `php artisan test --compact tests/Feature/ActualIndexTest.php`
Expected: FAIL (prop `tab` mancante).

- [ ] **Step 3: Implementa `index`**

```php
    public function index(Request $request, ActualVarianceReport $report): Response
    {
        $year = (int) $request->query('year', (string) now()->year);
        $month = (int) $request->query('month', (string) now()->month);
        $tab = $request->query('tab') === 'year' ? 'year' : 'month';

        $categories = Category::query()
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get(['id', 'name', 'type', 'color', 'sort_order']);

        $budgets = BudgetEntry::query()
            ->where('year', $year)
            ->where('month', $month)
            ->pluck('amount', 'category_id')
            ->map(fn ($amount): float => (float) $amount)
            ->all();

        return Inertia::render('actual/index', [
            'tab' => $tab,
            'year' => $year,
            'month' => $month,
            'expandedCategoryId' => $request->filled('category') ? $request->integer('category') : null,
            'categories' => $categories,
            'budgets' => (object) $budgets,
            'lines' => (object) $this->monthLines($categories, $year, $month),
            'report' => $report->build($year, CarbonImmutable::now()),
        ]);
    }

    /**
     * Every line making up each category actual in the month: confirmed movements and manual items.
     *
     * @param  Collection<int, Category>  $categories
     * @return array<int, list<array{key: string, kind: string, id: int, date: string, description: string, amount: float, bank_amount: float|null, kind_label: string|null}>>
     */
    private function monthLines(Collection $categories, int $year, int $month): array
    {
        $start = CarbonImmutable::create($year, $month, 1);
        $range = [$start->toDateString(), $start->endOfMonth()->toDateString()];
        $types = $categories->pluck('type', 'id');
        $lines = [];

        $movements = BankTransaction::query()
            ->where('status', TransactionStatus::Confirmed)
            ->whereNotNull('category_id')
            ->whereBetween('accounting_date', $range)
            ->get();

        foreach ($movements as $movement) {
            $categoryId = (int) $movement->category_id;
            $bankAmount = (float) $movement->amount;
            $lines[$categoryId][] = [
                'key' => "bank-{$movement->id}",
                'kind' => 'bank',
                'id' => $movement->id,
                'date' => $movement->accounting_date->toDateString(),
                'description' => $movement->merchant_label,
                'amount' => $types->get($categoryId) === CategoryType::Expense ? -$bankAmount : $bankAmount,
                'bank_amount' => $bankAmount,
                'kind_label' => $movement->kind->label(),
            ];
        }

        foreach (ActualItem::query()->whereBetween('date', $range)->get() as $item) {
            $lines[$item->category_id][] = [
                'key' => "manual-{$item->id}",
                'kind' => 'manual',
                'id' => $item->id,
                'date' => $item->date->toDateString(),
                'description' => $item->description,
                'amount' => (float) $item->amount,
                'bank_amount' => null,
                'kind_label' => null,
            ];
        }

        foreach ($lines as &$categoryLines) {
            usort($categoryLines, fn (array $a, array $b): int => [$a['date'], $a['key']] <=> [$b['date'], $b['key']]);
        }
        unset($categoryLines);

        return $lines;
    }
```

`use` nel controller: `App\Enums\CategoryType`, `App\Enums\TransactionStatus`, `App\Models\ActualItem`, `App\Models\BankTransaction`, `App\Services\ActualVarianceReport`, `Carbon\CarbonImmutable`, `Illuminate\Database\Eloquent\Collection`. Rimuovi `use App\Models\ActualEntry;` se non più usato.

Nota ordinamento: a parità di data `bank-*` precede `manual-*` (ordine alfabetico della key); il test lo usa.

- [ ] **Step 4: Verifica**

Run: `php artisan test --compact tests/Feature/ActualIndexTest.php`
Expected: PASS.

- [ ] **Step 5: Quality gate + commit**

```bash
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M && php artisan test --compact
git add app/Http/Controllers/App/ActualEntryController.php tests/Feature/ActualIndexTest.php
git commit -m "Serve month lines and yearly variance report to the actual page"
```

---

### Task 8: Logica scostamento frontend

**Files:**

- Create: `resources/js/lib/actual-variance.ts`
- Test: `resources/js/lib/actual-variance.test.ts`

**Interfaces:**

- Produces:
    - `type CategoryKind = 'income' | 'expense'`
    - `type VarianceTone = 'none' | 'good' | 'ok' | 'warn' | 'bad' | 'unbudgeted'`
    - `varianceTone(actual: number, budget: number, type: CategoryKind): VarianceTone`
    - `varianceRatio(actual: number, budget: number): number | null` (null se budget = 0)
    - `formatPercent(ratio: number | null): string` (`+40%`, `-6%`, `—`)
    - `TONE_CLASSES: Record<VarianceTone, string>` (classi Tailwind sfondo+testo cella)
    - `TONE_TEXT: Record<VarianceTone, string>` (solo colore testo)
    - `TONE_LABEL: Record<VarianceTone, string>` (legenda italiana)

- [ ] **Step 1: Test che fallisce**

```ts
import { describe, expect, it } from 'vitest';
import { formatPercent, varianceRatio, varianceTone } from './actual-variance';

describe('varianceTone', () => {
    it('classifies expenses by how much they exceed the budget', () => {
        expect(varianceTone(250, 300, 'expense')).toBe('good');
        expect(varianceTone(270, 300, 'expense')).toBe('ok');
        expect(varianceTone(330, 300, 'expense')).toBe('ok');
        expect(varianceTone(331, 300, 'expense')).toBe('warn');
        expect(varianceTone(390, 300, 'expense')).toBe('warn');
        expect(varianceTone(420, 300, 'expense')).toBe('bad');
    });

    it('inverts the logic for income', () => {
        expect(varianceTone(2400, 2000, 'income')).toBe('good');
        expect(varianceTone(1500, 2000, 'income')).toBe('warn');
        expect(varianceTone(1000, 2000, 'income')).toBe('bad');
    });

    it('handles missing budget', () => {
        expect(varianceTone(0, 0, 'expense')).toBe('none');
        expect(varianceTone(120, 0, 'expense')).toBe('unbudgeted');
        expect(varianceTone(-20, 0, 'expense')).toBe('good');
        expect(varianceTone(500, 0, 'income')).toBe('good');
    });
});

describe('varianceRatio / formatPercent', () => {
    it('returns the relative difference', () => {
        expect(varianceRatio(420, 300)).toBeCloseTo(0.4);
        expect(varianceRatio(10, 0)).toBeNull();
        expect(formatPercent(0.4)).toBe('+40%');
        expect(formatPercent(-0.0612)).toBe('-6%');
        expect(formatPercent(null)).toBe('—');
    });
});
```

- [ ] **Step 2: Verifica fallimento**

Run: `bun test:run resources/js/lib/actual-variance.test.ts`
Expected: FAIL, modulo non trovato.

- [ ] **Step 3: Implementa**

```ts
export type CategoryKind = 'income' | 'expense';

export type VarianceTone = 'none' | 'good' | 'ok' | 'warn' | 'bad' | 'unbudgeted';

const OK_THRESHOLD = 0.1;
const WARN_THRESHOLD = 0.3;

/** Relative difference of actual vs budget; null when there is no budget to compare with. */
export function varianceRatio(actual: number, budget: number): number | null {
    return budget === 0 ? null : (actual - budget) / budget;
}

/** How bad a category month looks: spending above budget (or earning below it) is worse. */
export function varianceTone(actual: number, budget: number, type: CategoryKind): VarianceTone {
    if (budget === 0) {
        if (actual === 0) {
            return 'none';
        }
        return type === 'expense' && actual > 0 ? 'unbudgeted' : 'good';
    }

    const ratio = (actual - budget) / budget;
    const overrun = type === 'expense' ? ratio : -ratio;

    if (overrun < -OK_THRESHOLD) {
        return 'good';
    }
    if (overrun <= OK_THRESHOLD + 1e-9) {
        return 'ok';
    }
    if (overrun <= WARN_THRESHOLD + 1e-9) {
        return 'warn';
    }
    return 'bad';
}

export function formatPercent(ratio: number | null): string {
    if (ratio === null) {
        return '—';
    }
    const rounded = Math.round(ratio * 100);
    return `${rounded > 0 ? '+' : ''}${rounded}%`;
}

export const TONE_CLASSES: Record<VarianceTone, string> = {
    none: 'text-muted-foreground',
    good: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    ok: 'bg-muted/60 text-foreground',
    warn: 'bg-amber-500/20 text-amber-800 dark:text-amber-300',
    bad: 'bg-red-500/20 text-red-700 dark:text-red-300',
    unbudgeted: 'bg-red-500/20 text-red-700 dark:text-red-300',
};

export const TONE_TEXT: Record<VarianceTone, string> = {
    none: 'text-muted-foreground',
    good: 'text-emerald-600 dark:text-emerald-400',
    ok: 'text-foreground',
    warn: 'text-amber-600 dark:text-amber-400',
    bad: 'text-destructive',
    unbudgeted: 'text-destructive',
};

export const TONE_LABEL: Record<VarianceTone, string> = {
    none: 'Nessun dato',
    good: 'Meglio del budget',
    ok: 'In linea (±10%)',
    warn: 'Sopra budget 10–30%',
    bad: 'Sopra budget oltre 30%',
    unbudgeted: 'Senza budget',
};
```

Nota: `330/300` = +10% esatto → `ok` (soglia inclusiva); l'epsilon evita errori di virgola mobile.

- [ ] **Step 4: Verifica**

Run: `bun test:run resources/js/lib/actual-variance.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
bun check:all
git add resources/js/lib/actual-variance.ts resources/js/lib/actual-variance.test.ts
git commit -m "Add budget variance classification helpers"
```

---

### Task 9: Tab Mese (componenti)

**Files:**

- Modify: `resources/js/components/shared/category-combobox.tsx` (`onCreate` opzionale)
- Create: `resources/js/components/actual/types.ts`
- Create: `resources/js/components/actual/manual-item-form.tsx`
- Create: `resources/js/components/actual/line-row.tsx`
- Create: `resources/js/components/actual/month-view.tsx`
- Test: `resources/js/components/actual/manual-item-form.test.tsx`

**Interfaces:**

- Consumes: props di Task 7, helper di Task 8, `CategoryCombobox`, endpoint Task 3 e 4.
- Produces: `<MonthView year month categories budgets lines expandedCategoryId />`; tipi condivisi in `types.ts`.

- [ ] **Step 1: Tipi condivisi** — `resources/js/components/actual/types.ts`

```ts
import type { CategoryKind } from '@/lib/actual-variance';

export interface ActualCategory {
    id: number;
    name: string;
    type: CategoryKind;
    color: string | null;
    sort_order: number;
}

export interface ActualLine {
    key: string;
    kind: 'bank' | 'manual';
    id: number;
    /** Y-m-d */
    date: string;
    description: string;
    /** Signed by category type: spending on an expense category is positive. */
    amount: number;
    /** Bank sign (negative = money out); only for bank lines. */
    bank_amount: number | null;
    kind_label: string | null;
}

export interface MonthCell {
    month: number;
    budget: number;
    actual: number;
    status: 'closed' | 'current' | 'future';
}

export interface VarianceRow {
    category_id: number | null;
    type: CategoryKind;
    months: MonthCell[];
    avg_budget: number | null;
    avg_actual: number | null;
    variance: number | null;
    variance_pct: number | null;
    peak: { month: number; actual: number } | null;
}

export interface VarianceReport {
    closed_months: number;
    rows: VarianceRow[];
    totals: { income: VarianceRow; expense: VarianceRow; net: VarianceRow };
}

/** "2026-08-15" → "15/08" without going through Date (no timezone shift). */
export function shortDate(date: string): string {
    const [, month, day] = date.split('-');
    return `${day}/${month}`;
}
```

- [ ] **Step 2: Test del form (fallisce)** — `manual-item-form.test.tsx`

```tsx
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ManualItemForm } from './manual-item-form';

describe('ManualItemForm', () => {
    it('submits the parsed amount with a dot decimal', () => {
        const onSubmit = vi.fn();
        render(
            <ManualItemForm
                initial={{ date: '2026-08-01', description: '', amount: '' }}
                onSubmit={onSubmit}
                onCancel={() => {}}
            />,
        );

        fireEvent.change(screen.getByLabelText('Descrizione'), { target: { value: 'Cena amici' } });
        fireEvent.change(screen.getByLabelText('Importo'), { target: { value: '60,50' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).toHaveBeenCalledWith({ date: '2026-08-01', description: 'Cena amici', amount: '60.5' });
    });

    it('does not submit without description or with zero amount', () => {
        const onSubmit = vi.fn();
        render(
            <ManualItemForm
                initial={{ date: '2026-08-01', description: '', amount: '0' }}
                onSubmit={onSubmit}
                onCancel={() => {}}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Salva' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Salva' })).toBeDisabled();
    });
});
```

Run: `bun test:run resources/js/components/actual/manual-item-form.test.tsx` → FAIL (modulo mancante).

- [ ] **Step 3: `manual-item-form.tsx`**

```tsx
import { type FormEvent, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { parseAmount } from '@/lib/utils';

export interface ManualItemValues {
    date: string;
    description: string;
    amount: string;
}

interface ManualItemFormProps {
    initial: ManualItemValues;
    processing?: boolean;
    onSubmit: (values: ManualItemValues) => void;
    onCancel: () => void;
}

/** Inline row form for adding or editing a manual actual item. Amount accepts "60,50". */
export function ManualItemForm({ initial, processing = false, onSubmit, onCancel }: ManualItemFormProps) {
    const [values, setValues] = useState(initial);
    const amount = parseAmount(values.amount);
    const valid = values.date !== '' && values.description.trim() !== '' && amount !== 0;

    function submit(event: FormEvent) {
        event.preventDefault();
        if (!valid) {
            return;
        }
        onSubmit({ date: values.date, description: values.description.trim(), amount: String(amount) });
    }

    return (
        <form onSubmit={submit} className="flex flex-wrap items-center gap-2 py-1">
            <Input
                type="date"
                aria-label="Data"
                value={values.date}
                onChange={(e) => setValues({ ...values, date: e.target.value })}
                className="h-7 w-36 text-xs"
            />
            <Input
                aria-label="Descrizione"
                placeholder="Descrizione…"
                value={values.description}
                onChange={(e) => setValues({ ...values, description: e.target.value })}
                className="h-7 min-w-48 flex-1 text-xs"
                autoFocus
            />
            <Input
                aria-label="Importo"
                inputMode="decimal"
                placeholder="0,00"
                value={values.amount}
                onChange={(e) => setValues({ ...values, amount: e.target.value })}
                className="h-7 w-28 text-right text-xs tabular-nums"
            />
            <Button type="submit" size="sm" className="h-7 text-xs" disabled={!valid || processing}>
                Salva
            </Button>
            <Button type="button" size="sm" variant="ghost" className="h-7 text-xs" onClick={onCancel}>
                Annulla
            </Button>
        </form>
    );
}
```

- [ ] **Step 4: `onCreate` opzionale nel combobox**

In `category-combobox.tsx`: `onCreate?: (name: string) => void;` e renderizza il `CommandItem value="__create"` solo se `onCreate` è definito:

```tsx
{
    onCreate && (
        <CommandItem value="__create" forceMount onSelect={create}>
            <Plus />
            {search.trim() !== '' ? `Crea "${search.trim()}"…` : 'Nuova categoria…'}
        </CommandItem>
    );
}
```

e in `create()`: `onCreate?.(search.trim());`.

- [ ] **Step 5: `line-row.tsx`**

```tsx
import { router } from '@inertiajs/react';
import { Landmark, Pencil, PenLine, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { CategoryCombobox } from '@/components/shared/category-combobox';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatCurrency } from '@/lib/utils';
import { ManualItemForm, type ManualItemValues } from './manual-item-form';
import { type ActualCategory, type ActualLine, shortDate } from './types';

interface LineRowProps {
    line: ActualLine;
    category: ActualCategory;
    categories: ActualCategory[];
}

const onError = () => toast.error('Operazione non riuscita');

export function LineRow({ line, category, categories }: LineRowProps) {
    const [editing, setEditing] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [processing, setProcessing] = useState(false);
    const options = { preserveScroll: true, onError, onFinish: () => setProcessing(false) };

    function reassign(categoryId: number | null, exclude: boolean) {
        setProcessing(true);
        router.patch(`/bank-transactions/${line.id}/reassign`, { category_id: categoryId, exclude }, options);
    }

    function update(values: ManualItemValues) {
        setProcessing(true);
        router.put(
            `/actual-items/${line.id}`,
            { ...values, category_id: category.id },
            { ...options, onSuccess: () => setEditing(false) },
        );
    }

    function destroy() {
        setProcessing(true);
        router.delete(`/actual-items/${line.id}`, options);
    }

    if (editing) {
        return (
            <li className="pl-8">
                <ManualItemForm
                    initial={{
                        date: line.date,
                        description: line.description,
                        amount: String(line.amount).replace('.', ','),
                    }}
                    processing={processing}
                    onSubmit={update}
                    onCancel={() => setEditing(false)}
                />
            </li>
        );
    }

    return (
        <li className="flex items-center gap-3 py-1 pl-8 text-xs">
            <span className="w-12 text-muted-foreground tabular-nums">{shortDate(line.date)}</span>
            <span className="min-w-0 flex-1 truncate" title={line.description}>
                {line.description}
            </span>
            <Badge variant="outline" className="gap-1 text-[10px] font-normal">
                {line.kind === 'bank' ? <Landmark className="size-3" /> : <PenLine className="size-3" />}
                {line.kind === 'bank' ? (line.kind_label ?? 'Banca') : 'Manuale'}
            </Badge>
            <span className="w-24 text-right font-medium tabular-nums">{formatCurrency(line.amount)}</span>
            <div className="flex w-60 justify-end gap-1">
                {line.kind === 'bank' ? (
                    <CategoryCombobox
                        categories={categories}
                        amount={line.bank_amount ?? 0}
                        value={category.id}
                        excluded={false}
                        disabled={processing}
                        ariaLabel={`Categoria di ${line.description}`}
                        onChange={reassign}
                    />
                ) : confirmDelete ? (
                    <>
                        <span className="self-center text-muted-foreground">Eliminare?</span>
                        <Button
                            size="sm"
                            variant="destructive"
                            className="h-7 text-xs"
                            disabled={processing}
                            onClick={destroy}
                        >
                            Elimina
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            className="h-7 text-xs"
                            onClick={() => setConfirmDelete(false)}
                        >
                            No
                        </Button>
                    </>
                ) : (
                    <>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label={`Modifica ${line.description}`}
                            onClick={() => setEditing(true)}
                        >
                            <Pencil className="size-3.5" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            className="size-7"
                            aria-label={`Elimina ${line.description}`}
                            onClick={() => setConfirmDelete(true)}
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </>
                )}
            </div>
        </li>
    );
}
```

La voce "Escludi" del combobox chiama `reassign(null, true)`: coerente con l'endpoint.

- [ ] **Step 6: `month-view.tsx`**

```tsx
import { router } from '@inertiajs/react';
import { ChevronDown, ChevronRight, Plus } from 'lucide-react';
import { Fragment, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { type CategoryKind, formatPercent, TONE_TEXT, varianceRatio, varianceTone } from '@/lib/actual-variance';
import { cn, formatCurrency } from '@/lib/utils';
import { LineRow } from './line-row';
import { ManualItemForm, type ManualItemValues } from './manual-item-form';
import type { ActualCategory, ActualLine } from './types';

interface MonthViewProps {
    year: number;
    month: number;
    categories: ActualCategory[];
    budgets: Record<number, number>;
    lines: Record<number, ActualLine[]>;
    expandedCategoryId: number | null;
}

const COLUMNS = 6;

function actualOf(lines: ActualLine[] | undefined): number {
    return (lines ?? []).reduce((sum, line) => sum + line.amount, 0);
}

function defaultDate(year: number, month: number): string {
    const today = new Date();
    const day = today.getFullYear() === year && today.getMonth() + 1 === month ? today.getDate() : 1;
    return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function MonthView({ year, month, categories, budgets, lines, expandedCategoryId }: MonthViewProps) {
    const [expanded, setExpanded] = useState<Set<number>>(
        () => new Set(expandedCategoryId ? [expandedCategoryId] : []),
    );
    const [adding, setAdding] = useState<number | null>(null);
    const [showEmpty, setShowEmpty] = useState(false);

    const toggle = (id: number) =>
        setExpanded((prev) => {
            const next = new Set(prev);
            if (next.has(id)) {
                next.delete(id);
            } else {
                next.add(id);
            }
            return next;
        });

    function addItem(categoryId: number, values: ManualItemValues) {
        router.post(
            '/actual-items',
            { ...values, category_id: categoryId },
            {
                preserveScroll: true,
                onSuccess: () => setAdding(null),
                onError: () => toast.error('Voce non salvata'),
            },
        );
    }

    const isEmpty = (c: ActualCategory) => (budgets[c.id] ?? 0) === 0 && (lines[c.id]?.length ?? 0) === 0;
    const groups: { type: CategoryKind; label: string }[] = [
        { type: 'income', label: 'Entrate' },
        { type: 'expense', label: 'Uscite' },
    ];
    const totals = Object.fromEntries(
        groups.map(({ type }) => {
            const ofType = categories.filter((c) => c.type === type);
            return [
                type,
                {
                    budget: ofType.reduce((s, c) => s + (budgets[c.id] ?? 0), 0),
                    actual: ofType.reduce((s, c) => s + actualOf(lines[c.id]), 0),
                },
            ];
        }),
    ) as Record<CategoryKind, { budget: number; actual: number }>;
    const emptyCategories = categories.filter(isEmpty);

    function categoryRows(category: ActualCategory) {
        const budget = budgets[category.id] ?? 0;
        const categoryLines = lines[category.id] ?? [];
        const actual = actualOf(categoryLines);
        const tone = varianceTone(actual, budget, category.type);
        const open = expanded.has(category.id);
        const usage = budget > 0 ? Math.min(actual / budget, 1.5) : actual > 0 ? 1.5 : 0;

        return (
            <Fragment key={category.id}>
                <TableRow className="cursor-pointer" onClick={() => toggle(category.id)} aria-expanded={open}>
                    <TableCell className="py-1.5 pl-3">
                        <div className="flex items-center gap-2">
                            {open ? <ChevronDown className="size-3.5" /> : <ChevronRight className="size-3.5" />}
                            <span
                                className="size-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: category.color ?? 'transparent' }}
                                aria-hidden="true"
                            />
                            <span className="text-xs font-medium">{category.name}</span>
                            <span className="text-[10px] text-muted-foreground">
                                {categoryLines.length > 0 ? `${categoryLines.length} voci` : ''}
                            </span>
                        </div>
                        <div className="mt-1 ml-9 h-1 max-w-48 rounded-full bg-muted" aria-hidden="true">
                            <div
                                className={cn(
                                    'h-1 rounded-full',
                                    tone === 'bad' || tone === 'unbudgeted'
                                        ? 'bg-destructive'
                                        : tone === 'warn'
                                          ? 'bg-amber-500'
                                          : 'bg-emerald-500',
                                )}
                                style={{ width: `${(usage / 1.5) * 100}%` }}
                            />
                        </div>
                    </TableCell>
                    <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                        {budget !== 0 ? formatCurrency(budget) : '—'}
                    </TableCell>
                    <TableCell className="text-right text-xs font-medium tabular-nums">
                        {actual !== 0 ? formatCurrency(actual) : '—'}
                    </TableCell>
                    <TableCell className={cn('text-right text-xs font-medium tabular-nums', TONE_TEXT[tone])}>
                        {actual !== 0 || budget !== 0
                            ? `${actual - budget > 0 ? '+' : ''}${formatCurrency(actual - budget)}`
                            : '—'}
                    </TableCell>
                    <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                        {tone === 'unbudgeted' ? 'senza budget' : formatPercent(varianceRatio(actual, budget))}
                    </TableCell>
                    <TableCell />
                </TableRow>
                {open && (
                    <TableRow className="hover:bg-transparent">
                        <TableCell colSpan={COLUMNS} className="bg-muted/20 py-1">
                            <ul className="divide-y">
                                {categoryLines.map((line) => (
                                    <LineRow key={line.key} line={line} category={category} categories={categories} />
                                ))}
                                {categoryLines.length === 0 && (
                                    <li className="py-1 pl-8 text-xs text-muted-foreground">Nessuna voce nel mese.</li>
                                )}
                                <li className="pl-8">
                                    {adding === category.id ? (
                                        <ManualItemForm
                                            initial={{ date: defaultDate(year, month), description: '', amount: '' }}
                                            onSubmit={(values) => addItem(category.id, values)}
                                            onCancel={() => setAdding(null)}
                                        />
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            className="h-7 text-xs"
                                            onClick={() => setAdding(category.id)}
                                        >
                                            <Plus className="size-3.5" /> Aggiungi voce
                                        </Button>
                                    )}
                                </li>
                            </ul>
                        </TableCell>
                    </TableRow>
                )}
            </Fragment>
        );
    }

    function totalRow(label: string, budget: number, actual: number, type: CategoryKind) {
        const tone = varianceTone(actual, budget, type);
        return (
            <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
                <TableCell className="py-1.5 pl-3 text-xs">{label}</TableCell>
                <TableCell className="text-right text-xs tabular-nums">{formatCurrency(budget)}</TableCell>
                <TableCell className="text-right text-xs tabular-nums">{formatCurrency(actual)}</TableCell>
                <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                    {`${actual - budget > 0 ? '+' : ''}${formatCurrency(actual - budget)}`}
                </TableCell>
                <TableCell className={cn('text-right text-xs tabular-nums', TONE_TEXT[tone])}>
                    {formatPercent(varianceRatio(actual, budget))}
                </TableCell>
                <TableCell />
            </TableRow>
        );
    }

    return (
        <div className="rounded-lg border bg-card shadow-xs">
            <Table className="text-xs">
                <TableHeader>
                    <TableRow className="bg-muted hover:bg-muted/40">
                        <TableHead className="min-w-64 pl-3 font-semibold">Categoria</TableHead>
                        <TableHead className="text-right font-semibold">Budget</TableHead>
                        <TableHead className="text-right font-semibold">Effettivo</TableHead>
                        <TableHead className="text-right font-semibold">Scostamento</TableHead>
                        <TableHead className="text-right font-semibold">%</TableHead>
                        <TableHead className="w-0" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {groups.map(({ type, label }) => (
                        <Fragment key={type}>
                            <TableRow className="bg-muted/60 hover:bg-muted/60">
                                <TableCell
                                    colSpan={COLUMNS}
                                    className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                >
                                    {label}
                                </TableCell>
                            </TableRow>
                            {categories.filter((c) => c.type === type && !isEmpty(c)).map(categoryRows)}
                            {totalRow(`Totale ${label}`, totals[type].budget, totals[type].actual, type)}
                        </Fragment>
                    ))}
                    {emptyCategories.length > 0 && (
                        <>
                            <TableRow className="cursor-pointer" onClick={() => setShowEmpty(!showEmpty)}>
                                <TableCell colSpan={COLUMNS} className="py-1.5 pl-3 text-xs text-muted-foreground">
                                    {showEmpty ? '▾' : '▸'} {emptyCategories.length} categorie vuote
                                </TableCell>
                            </TableRow>
                            {showEmpty && emptyCategories.map(categoryRows)}
                        </>
                    )}
                </TableBody>
                <TableFooter>
                    {totalRow(
                        'Saldo Netto',
                        totals.income.budget - totals.expense.budget,
                        totals.income.actual - totals.expense.actual,
                        'income',
                    )}
                </TableFooter>
            </Table>
        </div>
    );
}
```

Nota: la riga "Aggiungi voce" di una categoria vuota la sposta tra le non vuote dopo il salvataggio (ricarico Inertia): voluto.

- [ ] **Step 7: Verifica**

Run: `bun test:run && bun check:all`
Expected: PASS (se prettier segnala formattazione: `bunx prettier --write resources/js/components/actual` e rilancia).

- [ ] **Step 8: Commit**

```bash
git add resources/js/components/actual resources/js/components/shared/category-combobox.tsx
git commit -m "Add month view with expandable actual lines"
```

---

### Task 10: Tab Anno (matrice scostamenti)

**Files:**

- Create: `resources/js/components/actual/year-view.tsx`
- Create: `resources/js/components/actual/year-view.test.tsx`

**Interfaces:**

- Consumes: `VarianceReport`, `ActualCategory` (Task 9 `types.ts`), helper Task 8.
- Produces: `<YearView year categories report onOpenMonth={(month: number, categoryId: number) => void} />`.

- [ ] **Step 1: Test che fallisce**

```tsx
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import type { ActualCategory, VarianceReport, VarianceRow } from './types';
import { YearView } from './year-view';

function row(
    categoryId: number | null,
    type: 'income' | 'expense',
    budget: number,
    actual: number[],
    avg: [number | null, number | null],
    peak: VarianceRow['peak'],
): VarianceRow {
    const variance = avg[0] !== null && avg[1] !== null ? avg[1] - avg[0] : null;
    return {
        category_id: categoryId,
        type,
        months: Array.from({ length: 12 }, (_, i) => ({
            month: i + 1,
            budget,
            actual: actual[i] ?? 0,
            status: i < 8 ? 'closed' : i === 8 ? 'current' : 'future',
        })),
        avg_budget: avg[0],
        avg_actual: avg[1],
        variance,
        variance_pct: variance !== null && avg[0] ? variance / avg[0] : null,
        peak,
    };
}

const categories: ActualCategory[] = [
    { id: 1, name: 'Spesa', type: 'expense', color: null, sort_order: 1 },
    { id: 2, name: 'Pranzi/cene', type: 'expense', color: null, sort_order: 2 },
];

const report: VarianceReport = {
    closed_months: 8,
    rows: [
        row(1, 'expense', 500, [470, 470, 470, 470, 470, 470, 470, 470], [500, 470], { month: 1, actual: 470 }),
        row(2, 'expense', 300, [300, 350, 420, 420, 420, 420, 450, 580], [300, 420], { month: 8, actual: 580 }),
    ],
    totals: {
        income: row(null, 'income', 0, [], [0, 0], null),
        expense: row(null, 'expense', 800, [], [800, 890], null),
        net: row(null, 'income', -800, [], [-800, -890], null),
    },
};

describe('YearView', () => {
    it('sorts categories by variance and shows average, variance and peak', () => {
        render(<YearView year={2026} categories={categories} report={report} onOpenMonth={() => {}} />);

        const rows = screen.getAllByRole('row').filter((r) => r.dataset.categoryId);
        expect(rows.map((r) => r.dataset.categoryId)).toEqual(['2', '1']);
        const dining = within(rows[0]);
        expect(dining.getByText('+40%')).toBeInTheDocument();
        expect(dining.getByText(/ago/i)).toBeInTheDocument();
    });

    it('opens the month when a cell is clicked', () => {
        const onOpenMonth = vi.fn();
        render(<YearView year={2026} categories={categories} report={report} onOpenMonth={onOpenMonth} />);

        fireEvent.click(screen.getByRole('button', { name: /Pranzi\/cene agosto/i }));

        expect(onOpenMonth).toHaveBeenCalledWith(8, 2);
    });
});
```

Run: `bun test:run resources/js/components/actual/year-view.test.tsx` → FAIL.

- [ ] **Step 2: Implementa `year-view.tsx`**

```tsx
import { ArrowDownUp } from 'lucide-react';
import { Fragment, useMemo, useState } from 'react';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import {
    type CategoryKind,
    formatPercent,
    TONE_CLASSES,
    TONE_LABEL,
    TONE_TEXT,
    type VarianceTone,
    varianceTone,
} from '@/lib/actual-variance';
import { cn, formatCurrency, formatMonth } from '@/lib/utils';
import type { ActualCategory, MonthCell, VarianceReport, VarianceRow } from './types';

type SortKey = 'variance_pct' | 'variance' | 'avg_actual' | 'name';

interface YearViewProps {
    year: number;
    categories: ActualCategory[];
    report: VarianceReport;
    onOpenMonth: (month: number, categoryId: number) => void;
}

const MONTHS = Array.from({ length: 12 }, (_, i) => i + 1);
const LEGEND: VarianceTone[] = ['good', 'ok', 'warn', 'bad', 'unbudgeted'];

function compact(amount: number): string {
    return new Intl.NumberFormat('it-IT', { maximumFractionDigits: 0, useGrouping: 'always' }).format(amount);
}

/** Positive score = worse than budget, so sorting descending puts problems first for both income and expense. */
function badness(row: VarianceRow, key: SortKey): number {
    const sign = row.type === 'expense' ? 1 : -1;
    if (key === 'variance_pct') {
        return row.variance_pct !== null
            ? sign * row.variance_pct
            : row.variance !== null && row.variance !== 0
              ? sign * Infinity * Math.sign(row.variance)
              : -Infinity;
    }
    if (key === 'variance') {
        return sign * (row.variance ?? -Infinity);
    }
    return row.avg_actual ?? -Infinity;
}

function Cell({
    cell,
    type,
    label,
    onClick,
}: {
    cell: MonthCell;
    type: CategoryKind;
    label: string;
    onClick?: () => void;
}) {
    const tone = cell.status === 'future' ? 'none' : varianceTone(cell.actual, cell.budget, type);
    const content =
        cell.status === 'future'
            ? cell.budget !== 0
                ? compact(cell.budget)
                : ''
            : cell.actual !== 0
              ? compact(cell.actual)
              : cell.budget !== 0
                ? '0'
                : '';

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <button
                    type="button"
                    aria-label={label}
                    onClick={onClick}
                    disabled={!onClick}
                    className={cn(
                        'h-7 w-full rounded px-1 text-right text-[11px] tabular-nums',
                        TONE_CLASSES[tone],
                        cell.status === 'future' && 'text-muted-foreground/50 italic',
                        cell.status === 'current' && 'border border-dashed border-muted-foreground/50',
                        onClick && 'hover:ring-1 hover:ring-ring',
                    )}
                >
                    {content}
                </button>
            </TooltipTrigger>
            <TooltipContent>
                <div className="space-y-0.5 text-xs">
                    <div>Budget: {formatCurrency(cell.budget)}</div>
                    <div>Effettivo: {formatCurrency(cell.actual)}</div>
                    <div>Differenza: {formatCurrency(cell.actual - cell.budget)}</div>
                    {cell.status === 'current' && <div className="italic">Mese in corso (non in media)</div>}
                    {tone === 'unbudgeted' && <div>Senza budget</div>}
                </div>
            </TooltipContent>
        </Tooltip>
    );
}

export function YearView({ year, categories, report, onOpenMonth }: YearViewProps) {
    const [sort, setSort] = useState<SortKey>('variance_pct');
    const byId = useMemo(() => new Map(categories.map((c) => [c.id, c])), [categories]);

    const sortedRows = (type: CategoryKind) =>
        report.rows
            .filter((r) => r.type === type && r.category_id !== null)
            .filter((r) => r.months.some((m) => m.budget !== 0 || m.actual !== 0))
            .sort((a, b) =>
                sort === 'name'
                    ? (byId.get(a.category_id!)?.name ?? '').localeCompare(byId.get(b.category_id!)?.name ?? '')
                    : badness(b, sort) - badness(a, sort) || (b.avg_actual ?? 0) - (a.avg_actual ?? 0),
            );

    function sortHeader(key: SortKey, label: string, className?: string) {
        return (
            <TableHead className={cn('font-semibold', className)}>
                <button
                    type="button"
                    onClick={() => setSort(key)}
                    className={cn('inline-flex items-center gap-1', sort === key && 'text-foreground underline')}
                >
                    {label}
                    <ArrowDownUp className="size-3 opacity-50" />
                </button>
            </TableHead>
        );
    }

    function summaryCells(row: VarianceRow) {
        const tone =
            row.avg_budget === null || row.avg_actual === null
                ? 'none'
                : varianceTone(row.avg_actual, row.avg_budget, row.type);
        return (
            <>
                <TableCell className="text-right text-xs text-muted-foreground tabular-nums">
                    {row.avg_budget !== null ? formatCurrency(row.avg_budget) : '—'}
                </TableCell>
                <TableCell className="text-right text-xs font-medium tabular-nums">
                    {row.avg_actual !== null ? formatCurrency(row.avg_actual) : '—'}
                </TableCell>
                <TableCell className={cn('text-right text-xs font-medium tabular-nums', TONE_TEXT[tone])}>
                    {row.variance !== null ? `${row.variance > 0 ? '+' : ''}${formatCurrency(row.variance)}` : '—'}
                </TableCell>
                <TableCell className={cn('text-right text-xs font-semibold tabular-nums', TONE_TEXT[tone])}>
                    {tone === 'unbudgeted' ? 'senza budget' : formatPercent(row.variance_pct)}
                </TableCell>
                <TableCell className="text-xs whitespace-nowrap text-muted-foreground">
                    {row.peak ? `${formatMonth(row.peak.month).slice(0, 3)} ${compact(row.peak.actual)}` : '—'}
                </TableCell>
            </>
        );
    }

    function totalRow(label: string, row: VarianceRow) {
        return (
            <TableRow className="bg-muted/30 font-semibold hover:bg-muted/30">
                <TableCell className="sticky left-0 bg-muted py-1.5 pl-3 text-xs">{label}</TableCell>
                {summaryCells(row)}
                {row.months.map((cell) => (
                    <TableCell key={cell.month} className="p-0.5">
                        <Cell cell={cell} type={row.type} label={`${label} ${formatMonth(cell.month)}`} />
                    </TableCell>
                ))}
            </TableRow>
        );
    }

    const groups: { type: CategoryKind; label: string; total: VarianceRow }[] = [
        { type: 'income', label: 'Entrate', total: report.totals.income },
        { type: 'expense', label: 'Uscite', total: report.totals.expense },
    ];

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span>
                    {report.closed_months > 0
                        ? `Medie su ${report.closed_months} ${report.closed_months === 1 ? 'mese chiuso' : 'mesi chiusi'} del ${year}.`
                        : `Nessun mese chiuso nel ${year}: medie non disponibili.`}
                </span>
                {LEGEND.map((tone) => (
                    <span key={tone} className="inline-flex items-center gap-1">
                        <span className={cn('inline-block size-3 rounded', TONE_CLASSES[tone])} />
                        {TONE_LABEL[tone]}
                    </span>
                ))}
            </div>
            <div className="overflow-x-auto rounded-lg border bg-card shadow-xs">
                <Table className="text-xs">
                    <TableHeader>
                        <TableRow className="bg-muted hover:bg-muted">
                            <TableHead className="sticky left-0 min-w-44 bg-muted pl-3 font-semibold">
                                <button
                                    type="button"
                                    onClick={() => setSort('name')}
                                    className={cn(sort === 'name' && 'underline')}
                                >
                                    Categoria
                                </button>
                            </TableHead>
                            <TableHead className="text-right font-semibold">Budget/mese</TableHead>
                            {sortHeader('avg_actual', 'Media eff.', 'text-right')}
                            {sortHeader('variance', 'Scost. €', 'text-right')}
                            {sortHeader('variance_pct', 'Scost. %', 'text-right')}
                            <TableHead className="font-semibold">Picco</TableHead>
                            {MONTHS.map((m) => (
                                <TableHead key={m} className="min-w-14 text-center font-semibold capitalize">
                                    {formatMonth(m).slice(0, 3)}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {groups.map(({ type, label, total }) => (
                            <Fragment key={type}>
                                <TableRow className="bg-muted/60 hover:bg-muted/60">
                                    <TableCell
                                        colSpan={18}
                                        className="py-1.5 pl-3 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase"
                                    >
                                        {label}
                                    </TableCell>
                                </TableRow>
                                {sortedRows(type).map((row) => {
                                    const category = byId.get(row.category_id!);
                                    if (!category) {
                                        return null;
                                    }
                                    return (
                                        <TableRow key={category.id} data-category-id={category.id}>
                                            <TableCell className="sticky left-0 bg-card py-1 pl-3">
                                                <div className="flex items-center gap-2">
                                                    <span
                                                        className="size-2.5 shrink-0 rounded-full"
                                                        style={{ backgroundColor: category.color ?? 'transparent' }}
                                                        aria-hidden="true"
                                                    />
                                                    <span className="text-xs font-medium">{category.name}</span>
                                                </div>
                                            </TableCell>
                                            {summaryCells(row)}
                                            {row.months.map((cell) => (
                                                <TableCell key={cell.month} className="p-0.5">
                                                    <Cell
                                                        cell={cell}
                                                        type={row.type}
                                                        label={`${category.name} ${formatMonth(cell.month)}`}
                                                        onClick={
                                                            cell.status === 'future'
                                                                ? undefined
                                                                : () => onOpenMonth(cell.month, category.id)
                                                        }
                                                    />
                                                </TableCell>
                                            ))}
                                        </TableRow>
                                    );
                                })}
                                {totalRow(`Totale ${label}`, total)}
                            </Fragment>
                        ))}
                        {totalRow('Saldo netto', report.totals.net)}
                    </TableBody>
                </Table>
            </div>
        </div>
    );
}
```

Nota `badness` per `variance_pct` null con variance ≠ 0 (senza budget): lo mette in cima (±Infinity) perché è una spesa non prevista. `badness(b) - badness(a)` con due Infinity dà NaN → `|| (avg_actual)` fa da spareggio (NaN è falsy).

Se `TooltipTrigger`/`Tooltip` richiedono un `TooltipProvider` antenato (controlla `components/ui/tooltip.tsx` e `app-layout`), avvolgi il test in `<TooltipProvider>` e, se il layout non lo fornisce, avvolgi la tabella di `YearView` in `<TooltipProvider delayDuration={200}>`.

- [ ] **Step 3: Verifica**

Run: `bun test:run resources/js/components/actual/year-view.test.tsx && bun check:all`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add resources/js/components/actual/year-view.tsx resources/js/components/actual/year-view.test.tsx
git commit -m "Add yearly variance matrix view"
```

---

### Task 11: Pagina `actual/index.tsx` con tab e verifica in Chrome

**Files:**

- Modify (riscrittura): `resources/js/pages/actual/index.tsx`

**Interfaces:**

- Consumes: props Task 7, `MonthView` (Task 9), `YearView` (Task 10), `BankImportDialog`.

- [ ] **Step 1: Riscrivi la pagina**

Mantieni `SummaryCard` esistente (copialo in fondo al file invariato) e sostituisci tutto il resto con:

```tsx
import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { MonthView } from '@/components/actual/month-view';
import type { ActualCategory, ActualLine, VarianceReport } from '@/components/actual/types';
import { YearView } from '@/components/actual/year-view';
import { BankImportDialog } from '@/components/shared/bank-import-dialog';
import { Button } from '@/components/ui/button';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { cn, formatCurrency, formatMonth } from '@/lib/utils';

type Tab = 'month' | 'year';

interface Props {
    tab: Tab;
    year: number;
    month: number;
    expandedCategoryId: number | null;
    categories: ActualCategory[];
    budgets: Record<number, number>;
    lines: Record<number, ActualLine[]>;
    report: VarianceReport;
}

function visit(params: { tab: Tab; year: number; month: number; category?: number }) {
    router.get('/actual', params, { preserveScroll: params.category === undefined });
}

function Stepper({
    label,
    onPrev,
    onNext,
    prevLabel,
    nextLabel,
    wide,
}: {
    label: string;
    onPrev: () => void;
    onNext: () => void;
    prevLabel: string;
    nextLabel: string;
    wide?: boolean;
}) {
    return (
        <div className="flex items-center gap-1 rounded-lg border bg-card px-1 py-1">
            <Button variant="ghost" size="icon" className="size-8" onClick={onPrev} aria-label={prevLabel}>
                <ChevronLeft className="size-4" />
            </Button>
            <span
                className={cn(
                    'text-center text-sm font-semibold capitalize tabular-nums',
                    wide ? 'min-w-20' : 'min-w-12',
                )}
            >
                {label}
            </span>
            <Button variant="ghost" size="icon" className="size-8" onClick={onNext} aria-label={nextLabel}>
                <ChevronRight className="size-4" />
            </Button>
        </div>
    );
}

export default function ActualIndex({
    tab,
    year,
    month,
    expandedCategoryId,
    categories,
    budgets,
    lines,
    report,
}: Props) {
    const shiftMonth = (delta: number) => {
        const index = year * 12 + (month - 1) + delta;
        visit({ tab, year: Math.floor(index / 12), month: (index % 12) + 1 });
    };

    const sumBy = (type: 'income' | 'expense', source: 'budget' | 'actual') =>
        categories
            .filter((c) => c.type === type)
            .reduce(
                (sum, c) =>
                    sum +
                    (source === 'budget'
                        ? (budgets[c.id] ?? 0)
                        : (lines[c.id] ?? []).reduce((s, l) => s + l.amount, 0)),
                0,
            );

    const incomeBudget = sumBy('income', 'budget');
    const incomeActual = sumBy('income', 'actual');
    const expenseBudget = sumBy('expense', 'budget');
    const expenseActual = sumBy('expense', 'actual');

    return (
        <AppLayout>
            <Head title="Consuntivo" />

            <div className="space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">Consuntivo</h1>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {tab === 'month'
                                ? 'Voci del mese per categoria, confrontate con il budget.'
                                : 'Scostamenti budget/consuntivo per categoria nell’anno.'}
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        <Tabs value={tab} onValueChange={(value) => visit({ tab: value as Tab, year, month })}>
                            <TabsList>
                                <TabsTrigger value="month">Mese</TabsTrigger>
                                <TabsTrigger value="year">Anno</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <BankImportDialog />
                        <Stepper
                            label={String(year)}
                            onPrev={() => visit({ tab, year: year - 1, month })}
                            onNext={() => visit({ tab, year: year + 1, month })}
                            prevLabel="Anno precedente"
                            nextLabel="Anno successivo"
                        />
                        {tab === 'month' && (
                            <Stepper
                                label={formatMonth(month)}
                                onPrev={() => shiftMonth(-1)}
                                onNext={() => shiftMonth(1)}
                                prevLabel="Mese precedente"
                                nextLabel="Mese successivo"
                                wide
                            />
                        )}
                    </div>
                </div>

                {tab === 'month' ? (
                    <>
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <SummaryCard label="Entrate Budget" value={incomeBudget} variant="neutral" />
                            <SummaryCard
                                label="Entrate Effettive"
                                value={incomeActual}
                                variant={incomeActual >= incomeBudget ? 'positive' : 'negative'}
                                diff={incomeActual - incomeBudget}
                                diffType="income"
                            />
                            <SummaryCard label="Uscite Budget" value={expenseBudget} variant="neutral" />
                            <SummaryCard
                                label="Uscite Effettive"
                                value={expenseActual}
                                variant={expenseActual <= expenseBudget ? 'positive' : 'negative'}
                                diff={expenseActual - expenseBudget}
                                diffType="expense"
                            />
                        </div>
                        <MonthView
                            key={`${year}-${month}`}
                            year={year}
                            month={month}
                            categories={categories}
                            budgets={budgets}
                            lines={lines}
                            expandedCategoryId={expandedCategoryId}
                        />
                    </>
                ) : (
                    <YearView
                        year={year}
                        categories={categories}
                        report={report}
                        onOpenMonth={(m, categoryId) => visit({ tab: 'month', year, month: m, category: categoryId })}
                    />
                )}
            </div>
        </AppLayout>
    );
}
```

(`formatCurrency` è usato da `SummaryCard`.) `key={`${year}-${month}`}` resetta espansioni al cambio mese ma le conserva dopo un salvataggio nello stesso mese.

- [ ] **Step 2: Quality gate completo**

```bash
bunx prettier --write resources/js/pages/actual resources/js/components/actual
bun check:all && bun test:run
vendor/bin/pint --dirty --format agent && ./vendor/bin/phpstan analyse --memory-limit=512M && php artisan test --compact
```

Expected: tutto verde.

- [ ] **Step 3: Verifica in Chrome** (server già attivo su `http://localhost:8000`)

1. `/actual?year=2026&month=8`: nessun input nelle righe, nessuna label "+ X da banca"; click su una categoria di spesa → movimenti banca con combobox, "Aggiungi voce".
2. Aggiungi voce "Test verifica" 10,50 → toast, totale categoria +10,50, riga "Manuale". Modificala a 12 → totale aggiornato. Eliminala (conferma in-app) → totale tornato al valore iniziale.
3. Sposta un movimento in un'altra categoria → sparisce dalla lista, totale dell'altra categoria sale. Rimettilo nella categoria originale (per non alterare i dati).
4. Tab Anno: matrice colorata, legenda, colonna Scost. % ordinata decrescente, set/ott/… grigi, settembre tratteggiato. Hover cella → tooltip. Click cella agosto → tab Mese agosto con categoria espansa.
5. Anno 2027 (tab Anno): "Nessun mese chiuso", medie "—", nessun errore in console.
6. Importi ≥ 1.000 con il punto delle migliaia.

- [ ] **Step 4: Commit**

```bash
git add resources/js/pages/actual/index.tsx
git commit -m "Rebuild actual page with month detail and yearly variance tabs"
```
