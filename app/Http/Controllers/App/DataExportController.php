<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\ActualEntry;
use App\Models\BudgetEntry;
use App\Models\Category;
use App\Models\Reconciliation;
use App\Models\Setting;
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

            $zip->addFromString('actual_entries.csv', $this->buildCsv(
                ['id', 'category_id', 'year', 'month', 'amount', 'description', 'notes'],
                $this->collectRows(ActualEntry::query()->orderBy('id')->get(), fn (ActualEntry $e): array => [
                    (string) $e->id,
                    (string) $e->category_id,
                    (string) $e->year,
                    (string) $e->month,
                    (string) $e->amount,
                    $e->description ?? '',
                    $e->notes ?? '',
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
                Reconciliation::query()->delete();
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
                            'description' => ($row['description'] ?? '') !== '' ? $row['description'] : null,
                            'notes' => ($row['notes'] ?? '') !== '' ? $row['notes'] : null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
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
                $this->resetSequence('actual_entries');
                $this->resetSequence('reconciliations');
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

    private function resetSequence(string $table): void
    {
        $max = DB::table($table)->max('id') ?? 0;
        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), ?)", [$max + 1]);
    }
}
