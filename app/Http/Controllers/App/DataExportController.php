<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActualEntry;
use App\Models\ActualItem;
use App\Models\BankCategoryMapping;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\BudgetEntry;
use App\Models\BudgetEntryItem;
use App\Models\Category;
use App\Models\MerchantRule;
use App\Models\Reconciliation;
use App\Models\Setting;
use App\Services\ActualItemBackfill;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

class DataExportController extends Controller
{
    public function export(): StreamedResponse
    {
        $timestamp = now()->format('Y-m-d_His');
        $filename = "varys-backup-{$timestamp}.zip";

        return response()->streamDownload(function (): void {
            $tempFile = tempnam(sys_get_temp_dir(), 'varys_export_');

            $zip = new ZipArchive;
            $zip->open($tempFile, ZipArchive::OVERWRITE);

            $zip->addFromString('categories.csv', $this->buildCsv(
                ['id', 'name', 'type', 'is_invoiced', 'color', 'sort_order'],
                $this->collectRows(Category::query()->orderBy('id')->get(), fn (Category $c): array => [
                    (string) $c->id,
                    $c->name,
                    $c->type->value, // @phpstan-ignore property.nonObject
                    $c->is_invoiced ? '1' : '0',
                    $c->color ?? '',
                    (string) $c->sort_order,
                ]),
            ));

            $zip->addFromString('budget_entries.csv', $this->buildCsv(
                ['id', 'category_id', 'year', 'month', 'amount', 'notes'],
                $this->collectRows(BudgetEntry::query()->orderBy('id')->get(), fn (BudgetEntry $e): array => [
                    (string) $e->id,
                    (string) $e->category_id,
                    (string) $e->year,
                    (string) $e->month,
                    (string) $e->amount,
                    $e->notes ?? '',
                ]),
            ));

            $zip->addFromString('budget_entry_items.csv', $this->buildCsv(
                ['id', 'budget_entry_id', 'description', 'amount', 'sort_order'],
                $this->collectRows(BudgetEntryItem::query()->orderBy('id')->get(), fn (BudgetEntryItem $i): array => [
                    (string) $i->id,
                    (string) $i->budget_entry_id,
                    $i->description,
                    (string) $i->amount,
                    (string) $i->sort_order,
                ]),
            ));

            $zip->addFromString('actual_entries.csv', $this->buildCsv(
                ['id', 'category_id', 'year', 'month', 'amount', 'manual_amount', 'imported_amount', 'description', 'notes'],
                $this->collectRows(ActualEntry::query()->orderBy('id')->get(), fn (ActualEntry $e): array => [
                    (string) $e->id,
                    (string) $e->category_id,
                    (string) $e->year,
                    (string) $e->month,
                    (string) $e->amount,
                    (string) $e->manual_amount,
                    (string) $e->imported_amount,
                    $e->description ?? '',
                    $e->notes ?? '',
                ]),
            ));

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

            $zip->addFromString('reconciliations.csv', $this->buildCsv(
                ['id', 'year', 'month', 'declared_balance', 'calculated_balance', 'adjustment', 'notes'],
                $this->collectRows(Reconciliation::query()->orderBy('id')->get(), fn (Reconciliation $r): array => [
                    (string) $r->id,
                    (string) $r->year,
                    (string) $r->month,
                    (string) $r->declared_balance,
                    (string) $r->calculated_balance,
                    (string) $r->adjustment,
                    $r->notes ?? '',
                ]),
            ));

            $zip->addFromString('bank_imports.csv', $this->buildCsv(
                ['id', 'bank', 'original_filename', 'period_start', 'period_end', 'rows_total', 'rows_imported', 'rows_duplicates', 'status', 'completed_at'],
                $this->collectRows(BankImport::query()->orderBy('id')->get(), fn (BankImport $i): array => [
                    (string) $i->id,
                    $i->bank->value,
                    $i->original_filename,
                    $i->period_start?->toDateString() ?? '',
                    $i->period_end?->toDateString() ?? '',
                    (string) $i->rows_total,
                    (string) $i->rows_imported,
                    (string) $i->rows_duplicates,
                    $i->status->value,
                    $i->completed_at?->toDateTimeString() ?? '',
                ]),
            ));

            $zip->addFromString('bank_transactions.csv', $this->buildCsv(
                ['id', 'bank_import_id', 'fingerprint', 'kind', 'accounting_date', 'operation_date', 'booking_date', 'amount', 'raw_description', 'merchant_key', 'merchant_label', 'payment_instrument', 'bank_category', 'category_id', 'categorization_source', 'confidence', 'status'],
                $this->collectRows(BankTransaction::query()->orderBy('id')->get(), fn (BankTransaction $t): array => [
                    (string) $t->id,
                    (string) $t->bank_import_id,
                    $t->fingerprint,
                    $t->kind->value,
                    $t->accounting_date->toDateString(),
                    $t->operation_date->toDateString(),
                    $t->booking_date?->toDateString() ?? '',
                    (string) $t->amount,
                    $t->raw_description,
                    $t->merchant_key,
                    $t->merchant_label,
                    $t->payment_instrument ?? '',
                    $t->bank_category ?? '',
                    (string) ($t->category_id ?? ''),
                    $t->categorization_source->value ?? '',
                    (string) ($t->confidence ?? ''),
                    $t->status->value,
                ]),
            ));

            $zip->addFromString('merchant_rules.csv', $this->buildCsv(
                ['id', 'match_type', 'pattern', 'category_id', 'always_ask', 'exclude', 'times_confirmed'],
                $this->collectRows(MerchantRule::query()->orderBy('id')->get(), fn (MerchantRule $r): array => [
                    (string) $r->id,
                    $r->match_type->value,
                    $r->pattern,
                    (string) ($r->category_id ?? ''),
                    $r->always_ask ? '1' : '0',
                    $r->exclude ? '1' : '0',
                    (string) $r->times_confirmed,
                ]),
            ));

            $zip->addFromString('bank_category_mappings.csv', $this->buildCsv(
                ['id', 'bank', 'bank_category', 'category_id'],
                $this->collectRows(BankCategoryMapping::query()->orderBy('id')->get(), fn (BankCategoryMapping $m): array => [
                    (string) $m->id,
                    $m->bank->value,
                    $m->bank_category,
                    (string) ($m->category_id ?? ''),
                ]),
            ));

            $zip->addFromString('settings.csv', $this->buildCsv(
                ['key', 'value'],
                $this->collectRows(Setting::query()->orderBy('key')->get(), fn (Setting $s): array => [
                    $s->key,
                    $s->value ?? '',
                ]),
            ));

            $zip->close();

            echo file_get_contents($tempFile);
            unlink($tempFile);
        }, $filename, [
            'Content-Type' => 'application/zip',
        ]);
    }

    public function import(Request $request): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:zip', 'max:10240'],
        ]);

        /** @var UploadedFile $uploadedFile */
        $uploadedFile = $request->file('file');

        $zip = new ZipArchive;
        $result = $zip->open($uploadedFile->getRealPath());

        if ($result !== true) {
            return back()->withErrors(['file' => 'Impossibile aprire il file ZIP.']);
        }

        $requiredFiles = ['categories.csv', 'settings.csv'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $requiredFiles = array_filter($requiredFiles, fn (string $f): bool => $f !== $name);
        }

        if (count($requiredFiles) > 0) {
            $zip->close();

            return back()->withErrors(['file' => 'Il file ZIP deve contenere almeno: '.implode(', ', $requiredFiles)]);
        }

        try {
            DB::transaction(function () use ($zip): void {
                // Order matters: entries depend on categories
                BankTransaction::query()->delete();
                BankImport::query()->delete();
                MerchantRule::query()->delete();
                BankCategoryMapping::query()->delete();
                Reconciliation::query()->delete();
                ActualItem::query()->delete();
                ActualEntry::query()->delete();
                BudgetEntry::query()->delete();
                Category::query()->delete();
                Setting::query()->delete();

                // Use DB::table() to bypass $guarded and preserve original IDs
                $now = now();

                // Import categories
                $categoriesCsv = $zip->getFromName('categories.csv');
                if ($categoriesCsv !== false) {
                    foreach ($this->parseCsv($categoriesCsv) as $row) {
                        DB::table('categories')->insert([
                            'id' => (int) $row['id'],
                            'name' => $row['name'],
                            'type' => $row['type'],
                            'is_invoiced' => (bool) (int) $row['is_invoiced'],
                            'color' => $row['color'] !== '' ? $row['color'] : null,
                            'sort_order' => (int) $row['sort_order'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Import budget entries
                $budgetCsv = $zip->getFromName('budget_entries.csv');
                if ($budgetCsv !== false) {
                    foreach ($this->parseCsv($budgetCsv) as $row) {
                        DB::table('budget_entries')->insert([
                            'id' => (int) $row['id'],
                            'category_id' => (int) $row['category_id'],
                            'year' => (int) $row['year'],
                            'month' => (int) $row['month'],
                            'amount' => $row['amount'],
                            'notes' => $row['notes'] !== '' ? $row['notes'] : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Import budget entry items (absent in older backups)
                $budgetItemsCsv = $zip->getFromName('budget_entry_items.csv');
                if ($budgetItemsCsv !== false) {
                    foreach ($this->parseCsv($budgetItemsCsv) as $row) {
                        DB::table('budget_entry_items')->insert([
                            'id' => (int) $row['id'],
                            'budget_entry_id' => (int) $row['budget_entry_id'],
                            'description' => $row['description'],
                            'amount' => $row['amount'],
                            'sort_order' => (int) $row['sort_order'],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Import actual entries
                $actualCsv = $zip->getFromName('actual_entries.csv');
                if ($actualCsv !== false) {
                    foreach ($this->parseCsv($actualCsv) as $row) {
                        DB::table('actual_entries')->insert([
                            'id' => (int) $row['id'],
                            'category_id' => (int) $row['category_id'],
                            'year' => (int) $row['year'],
                            'month' => (int) $row['month'],
                            'amount' => $row['amount'],
                            // Older backups have no split: everything was typed by hand.
                            'manual_amount' => $row['manual_amount'] ?? $row['amount'],
                            'imported_amount' => $row['imported_amount'] ?? '0',
                            'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                            'notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

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

                // Import reconciliations
                $reconciliationsCsv = $zip->getFromName('reconciliations.csv');
                if ($reconciliationsCsv !== false) {
                    foreach ($this->parseCsv($reconciliationsCsv) as $row) {
                        DB::table('reconciliations')->insert([
                            'id' => (int) $row['id'],
                            'year' => (int) $row['year'],
                            'month' => (int) $row['month'],
                            'declared_balance' => $row['declared_balance'],
                            'calculated_balance' => $row['calculated_balance'],
                            'adjustment' => $row['adjustment'],
                            'notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                // Import bank import data (absent in older backups)
                $this->insertRows($zip, 'bank_imports', fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'bank' => $row['bank'],
                    'original_filename' => $row['original_filename'],
                    'period_start' => $this->nullable($row['period_start']),
                    'period_end' => $this->nullable($row['period_end']),
                    'rows_total' => (int) $row['rows_total'],
                    'rows_imported' => (int) $row['rows_imported'],
                    'rows_duplicates' => (int) $row['rows_duplicates'],
                    'status' => $row['status'],
                    'completed_at' => $this->nullable($row['completed_at']),
                ]);

                $this->insertRows($zip, 'bank_transactions', fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'bank_import_id' => (int) $row['bank_import_id'],
                    'fingerprint' => $row['fingerprint'],
                    'kind' => $row['kind'],
                    'accounting_date' => $row['accounting_date'],
                    'operation_date' => $row['operation_date'],
                    'booking_date' => $this->nullable($row['booking_date']),
                    'amount' => $row['amount'],
                    'raw_description' => $row['raw_description'],
                    'merchant_key' => $row['merchant_key'],
                    'merchant_label' => $row['merchant_label'],
                    'payment_instrument' => $this->nullable($row['payment_instrument']),
                    'bank_category' => $this->nullable($row['bank_category']),
                    'category_id' => $this->nullable($row['category_id']),
                    'categorization_source' => $this->nullable($row['categorization_source']),
                    'confidence' => $this->nullable($row['confidence']),
                    'status' => $row['status'],
                ]);

                $this->insertRows($zip, 'merchant_rules', fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'match_type' => $row['match_type'],
                    'pattern' => $row['pattern'],
                    'category_id' => $this->nullable($row['category_id']),
                    'always_ask' => (bool) (int) $row['always_ask'],
                    'exclude' => (bool) (int) $row['exclude'],
                    'times_confirmed' => (int) $row['times_confirmed'],
                ]);

                $this->insertRows($zip, 'bank_category_mappings', fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'bank' => $row['bank'],
                    'bank_category' => $row['bank_category'],
                    'category_id' => $this->nullable($row['category_id']),
                ]);

                // Import settings
                $settingsCsv = $zip->getFromName('settings.csv');
                if ($settingsCsv !== false) {
                    foreach ($this->parseCsv($settingsCsv) as $row) {
                        Setting::setValue($row['key'], $row['value'] !== '' ? $row['value'] : null);
                    }
                }

                // Reset sequences for PostgreSQL
                $this->resetSequence('categories');
                $this->resetSequence('budget_entries');
                $this->resetSequence('budget_entry_items');
                $this->resetSequence('actual_entries');
                $this->resetSequence('actual_items');
                $this->resetSequence('reconciliations');
                $this->resetSequence('bank_imports');
                $this->resetSequence('bank_transactions');
                $this->resetSequence('merchant_rules');
                $this->resetSequence('bank_category_mappings');
            });
        } catch (\Throwable $e) {
            $zip->close();

            return back()->withErrors(['file' => 'Errore durante l\'importazione: '.$e->getMessage()]);
        }

        $zip->close();

        return redirect()->route('settings.index')->with('success', 'Dati importati con successo.');
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Collection<int, T>  $collection
     * @param  callable(T): list<string>  $mapper
     * @return list<list<string>>
     */
    private function collectRows(Collection $collection, callable $mapper): array
    {
        $rows = [];
        foreach ($collection as $model) {
            $rows[] = $mapper($model);
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function buildCsv(array $headers, array $rows): string
    {
        /** @var resource $handle */
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $content): array
    {
        $lines = str_getcsv($content, "\n");
        if (count($lines) < 2) {
            return [];
        }

        $headers = str_getcsv(array_shift($lines));
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line);
            if (count($values) === count($headers)) {
                $rows[] = array_combine($headers, $values);
            }
        }

        return $rows;
    }

    /**
     * @param  callable(array<string, string>): array<string, mixed>  $mapper
     */
    private function insertRows(ZipArchive $zip, string $table, callable $mapper): void
    {
        $csv = $zip->getFromName("{$table}.csv");

        if ($csv === false) {
            return;
        }

        $now = now();

        foreach ($this->parseCsv($csv) as $row) {
            DB::table($table)->insert([...$mapper($row), 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    private function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }

    private function resetSequence(string $table): void
    {
        $max = DB::table($table)->max('id') ?? 0;
        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), ?)", [$max + 1]);
    }
}
