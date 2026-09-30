<?php

namespace App\Services\BankImport;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Pipeline: parse the bank export → mask identifiers (cards, IBANs, fiscal codes, accounts, emails)
 * → store movements for review → categorize locally. The file itself is never stored.
 */
class BankStatementImporter
{
    public function __construct(
        private readonly StatementParser $parser,
        private readonly MerchantNormalizer $normalizer,
        private readonly LocalCategorizer $categorizer,
    ) {}

    /**
     * Idempotent: movements already stored are never stored again, and a file with no new movements
     * creates no import at all (StatementAlreadyImportedException points to the existing one).
     *
     * @throws UnsupportedStatementException
     * @throws StatementAlreadyImportedException
     */
    public function import(string $path, string $originalFilename, ?Bank $bank = null): BankImport
    {
        $statement = $this->parser->parseFile($path, $bank);
        $rows = $this->fingerprinted($statement);

        if ($rows === []) {
            throw new UnsupportedStatementException('Il file non contiene movimenti.');
        }

        $existing = BankTransaction::query()->whereIn('fingerprint', array_column($rows, 'fingerprint'));

        if ((clone $existing)->count() === count($rows)) {
            throw new StatementAlreadyImportedException((int) $existing->max('bank_import_id'));
        }

        return DB::transaction(function () use ($statement, $rows, $originalFilename): BankImport {
            $import = BankImport::query()->create([
                'bank' => $statement->bank,
                'original_filename' => $originalFilename,
                'rows_total' => $statement->rowsTotal,
                'status' => BankImportStatus::Review,
            ]);

            $anonymizer = new StatementAnonymizer;
            $imported = 0;
            $duplicates = 0;

            foreach ($rows as ['fingerprint' => $fingerprint, 'description' => $description, 'transaction' => $transaction]) {
                // createOrFirst relies on the unique fingerprint: safe even with two uploads at the same time.
                $stored = BankTransaction::query()->createOrFirst(['fingerprint' => $fingerprint], [
                    'bank_import_id' => $import->id,
                    'kind' => $transaction->kind,
                    'accounting_date' => $transaction->bookingDate,
                    'operation_date' => $transaction->operationDate,
                    'booking_date' => $transaction->bookingDate,
                    'amount' => round($transaction->amount, 2),
                    'raw_description' => $anonymizer->maskIdentifiers($description),
                    'merchant_key' => $this->normalizer->normalize($anonymizer->maskIdentifiers($transaction->merchantLabel)),
                    'merchant_label' => $anonymizer->maskIdentifiers($transaction->merchantLabel),
                    'payment_instrument' => $transaction->paymentInstrument,
                    'bank_category' => $transaction->bankCategory,
                    'status' => TransactionStatus::ToReview,
                ]);

                $stored->wasRecentlyCreated ? $imported++ : $duplicates++;
            }

            $import->update([
                'rows_imported' => $imported,
                'rows_duplicates' => $duplicates,
                'period_start' => $import->transactions()->min('accounting_date'),
                'period_end' => $import->transactions()->max('accounting_date'),
                'anonymization_stats' => array_intersect_key(
                    $anonymizer->stats(),
                    array_flip(['cards', 'ibans', 'fiscal_codes', 'accounts', 'emails']),
                ),
            ]);

            $this->categorizer->categorize($import);

            return $import;
        });
    }

    /**
     * The fingerprint identifies a movement across files: bank, booking date, amount, description
     * and the occurrence number, so identical movements on the same day (e.g. two coffees) stay distinct.
     *
     * @return list<array{fingerprint: string, description: string, transaction: ParsedTransaction}>
     */
    private function fingerprinted(ParsedStatement $statement): array
    {
        $occurrences = [];
        $rows = [];

        foreach ($statement->transactions as $transaction) {
            $description = trim((string) preg_replace('/\s+/u', ' ', $transaction->rawDescription));
            $identity = implode('|', [
                $statement->bank->value,
                $transaction->bookingDate->toDateString(),
                number_format($transaction->amount, 2, '.', ''),
                mb_strtoupper($description),
            ]);
            $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;

            $rows[] = [
                'fingerprint' => hash('sha256', $identity.'|'.$occurrences[$identity]),
                'description' => $description,
                'transaction' => $transaction,
            ];
        }

        return $rows;
    }
}
