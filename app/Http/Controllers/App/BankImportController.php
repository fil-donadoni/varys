<?php

namespace App\Http\Controllers\App;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use App\Enums\CategorizationSource;
use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreBankImportRequest;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Services\BankImport\BankImportReviewer;
use App\Services\BankImport\BankStatementImporter;
use App\Services\BankImport\Llm\CategorizerException;
use App\Services\BankImport\Llm\LlmCategorizationService;
use App\Services\BankImport\Llm\LlmPayloadBuilder;
use App\Services\BankImport\Llm\MerchantCategorizer;
use App\Services\BankImport\StatementAlreadyImportedException;
use App\Services\BankImport\UnsupportedStatementException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

class BankImportController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('bank-imports/index', [
            'imports' => BankImport::query()->latest()->get()->map(fn (BankImport $import): array => [
                'id' => $import->id,
                'bank' => $import->bank->label(),
                'original_filename' => $import->original_filename,
                'period_start' => $import->period_start?->toDateString(),
                'period_end' => $import->period_end?->toDateString(),
                'rows_imported' => $import->rows_imported,
                'rows_duplicates' => $import->rows_duplicates,
                'status' => $import->status->value,
                'status_label' => $import->status->label(),
                'created_at' => $import->created_at?->toDateTimeString(),
            ]),
        ]);
    }

    public function store(StoreBankImportRequest $request, BankStatementImporter $importer): RedirectResponse
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $bank = $request->filled('bank') ? Bank::from((string) $request->string('bank')) : null;

        try {
            $import = $importer->import((string) $file->getRealPath(), $file->getClientOriginalName(), $bank);
        } catch (UnsupportedStatementException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (StatementAlreadyImportedException $e) {
            return redirect()->route('bank-imports.show', $e->bankImportId)
                ->with('notice', 'File già importato: nessun movimento nuovo, ti mostro l\'import esistente.');
        }

        return redirect()->route('bank-imports.show', $import);
    }

    public function show(BankImport $bankImport, LlmPayloadBuilder $payloadBuilder, MerchantCategorizer $categorizer): Response
    {
        $transactions = $bankImport->transactions()
            ->with('category:id,name,type,color')
            ->orderBy('accounting_date')
            ->orderBy('id')
            ->get()
            ->map(fn (BankTransaction $t): array => [
                'id' => $t->id,
                'kind' => $t->kind->value,
                'kind_label' => $t->kind->label(),
                'accounting_date' => $t->accounting_date->toDateString(),
                'operation_date' => $t->operation_date->toDateString(),
                'amount' => (float) $t->amount,
                'merchant_key' => $t->merchant_key,
                'merchant_label' => $t->merchant_label,
                'raw_description' => $t->raw_description,
                'payment_instrument' => $t->payment_instrument,
                'bank_category' => $t->bank_category,
                'category_id' => $t->category_id,
                'source' => $t->categorization_source?->value,
                'source_label' => $t->categorization_source?->label(),
                'confidence' => $t->confidence !== null ? (float) $t->confidence : null,
                'status' => $t->status->value,
            ]);

        return Inertia::render('bank-imports/show', [
            'bankImport' => [
                'id' => $bankImport->id,
                'bank' => $bankImport->bank->label(),
                'original_filename' => $bankImport->original_filename,
                'period_start' => $bankImport->period_start?->toDateString(),
                'period_end' => $bankImport->period_end?->toDateString(),
                'rows_total' => $bankImport->rows_total,
                'rows_imported' => $bankImport->rows_imported,
                'rows_duplicates' => $bankImport->rows_duplicates,
                'status' => $bankImport->status->value,
            ],
            'transactions' => $transactions,
            'pipeline' => [
                'anonymization' => $bankImport->anonymization_stats ?? [],
                'kinds' => collect(TransactionKind::cases())
                    ->map(fn (TransactionKind $kind): array => [
                        'label' => $kind->label(),
                        'count' => $transactions->where('kind', $kind->value)->count(),
                    ])
                    ->filter(fn (array $kind): bool => $kind['count'] > 0)
                    ->values(),
                'local' => [
                    'memory' => $transactions->whereIn('source', [CategorizationSource::Memory->value, CategorizationSource::SimilarMemory->value, CategorizationSource::Keyword->value])->where('status', '!=', TransactionStatus::Excluded->value)->count(),
                    'excluded' => $transactions->where('status', TransactionStatus::Excluded->value)->count(),
                    'bank' => $transactions->where('source', CategorizationSource::Bank->value)->count(),
                ],
                'llm' => [
                    'provider' => $categorizer->name(),
                    'unavailable_reason' => $categorizer->unavailableReason(),
                    'ran_at' => $bankImport->llm_ran_at?->toDateTimeString(),
                    'stats' => $bankImport->llm_stats,
                    'preview' => $bankImport->status === BankImportStatus::Review ? $payloadBuilder->preview($bankImport) : null,
                ],
            ],
            'categories' => Category::query()->orderBy('type')->orderBy('sort_order')->get(['id', 'name', 'type', 'color']),
        ]);
    }

    public function categorize(Request $request, BankImport $bankImport, LlmCategorizationService $service): RedirectResponse
    {
        /** @var list<string> $excluded */
        $excluded = $request->validate([
            'excluded' => ['array'],
            'excluded.*' => ['string'],
        ])['excluded'] ?? [];

        if ($bankImport->status === BankImportStatus::Completed) {
            return back()->withErrors(['llm' => 'Import già confermato.']);
        }

        try {
            $service->run($bankImport, $excluded);
        } catch (CategorizerException $e) {
            return back()->withErrors(['llm' => $e->getMessage()]);
        }

        return back();
    }

    public function complete(BankImport $bankImport, BankImportReviewer $reviewer): RedirectResponse
    {
        if ($bankImport->status === BankImportStatus::Completed) {
            return back()->withErrors(['import' => 'Import già confermato.']);
        }

        if ($bankImport->transactions()->where('status', TransactionStatus::ToReview)->exists()) {
            return back()->withErrors(['import' => 'Ci sono ancora movimenti da confermare.']);
        }

        $reviewer->complete($bankImport);

        $latest = $bankImport->transactions()->where('status', TransactionStatus::Confirmed)->max('accounting_date');
        $month = $latest !== null ? now()->parse((string) $latest) : now();

        return redirect()->route('actual.index', ['year' => $month->year, 'month' => $month->month])
            ->with('success', 'Import confermato: consuntivi aggiornati.');
    }

    public function destroy(BankImport $bankImport, BankImportReviewer $reviewer): RedirectResponse
    {
        $reviewer->delete($bankImport);

        return redirect()->route('bank-imports.index')->with('success', 'Import eliminato.');
    }
}
