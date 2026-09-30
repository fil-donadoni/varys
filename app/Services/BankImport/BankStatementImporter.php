<?php

namespace App\Services\BankImport;

use App\Enums\Bank;
use App\Enums\BankImportStatus;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Parses a bank export and stores its movements for review. The file itself is never stored.
 */
class BankStatementImporter
{
    public function __construct(
        private readonly StatementParser $parser,
        private readonly MerchantNormalizer $normalizer,
        private readonly LocalCategorizer $categorizer,
    ) {}

    public function import(string $path, string $originalFilename, ?Bank $bank = null): BankImport
    {
        $statement = $this->parser->parseFile($path, $bank);

        return DB::transaction(function () use ($statement, $originalFilename): BankImport {
            $import = BankImport::query()->create([
                'bank' => $statement->bank,
                'original_filename' => $originalFilename,
                'rows_total' => $statement->rowsTotal,
                'status' => BankImportStatus::Review,
            ]);

            $occurrences = [];
            $imported = 0;
            $duplicates = 0;

            foreach ($statement->transactions as $transaction) {
                $description = trim((string) preg_replace('/\s+/u', ' ', $transaction->rawDescription));
                $identity = implode('|', [
                    $statement->bank->value,
                    $transaction->bookingDate->toDateString(),
                    number_format($transaction->amount, 2, '.', ''),
                    mb_strtoupper($description),
                ]);
                // Identical movements on the same day (e.g. two coffees) stay distinct.
                $occurrences[$identity] = ($occurrences[$identity] ?? 0) + 1;
                $fingerprint = hash('sha256', $identity.'|'.$occurrences[$identity]);

                if (BankTransaction::query()->where('fingerprint', $fingerprint)->exists()) {
                    $duplicates++;

                    continue;
                }

                $import->transactions()->create([
                    'fingerprint' => $fingerprint,
                    'kind' => $transaction->kind,
                    'accounting_date' => $transaction->bookingDate,
                    'operation_date' => $transaction->operationDate,
                    'booking_date' => $transaction->bookingDate,
                    'amount' => round($transaction->amount, 2),
                    'raw_description' => $description,
                    'merchant_key' => $this->normalizer->normalize($transaction->merchantLabel),
                    'merchant_label' => $transaction->merchantLabel,
                    'payment_instrument' => $transaction->paymentInstrument,
                    'bank_category' => $transaction->bankCategory,
                    'status' => TransactionStatus::ToReview,
                ]);
                $imported++;
            }

            $import->update([
                'rows_imported' => $imported,
                'rows_duplicates' => $duplicates,
                'period_start' => $import->transactions()->min('accounting_date'),
                'period_end' => $import->transactions()->max('accounting_date'),
            ]);

            $this->categorizer->categorize($import);

            return $import;
        });
    }
}
