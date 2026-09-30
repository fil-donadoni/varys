<?php

namespace App\Services\BankImport\Parsers;

use App\Enums\Bank;
use App\Enums\TransactionKind;
use App\Services\BankImport\ParsedStatement;
use App\Services\BankImport\ParsedTransaction;

class IntesaParser implements BankStatementParser
{
    private const array REQUIRED_COLUMNS = ['data', 'operazione', 'dettagli', 'conto o carta', 'categoria', 'importo'];

    private const string CARD_DETAILS_PATTERN = '/mediante la carta|carta n\.|pagamento su pos/i';

    public function bank(): Bank
    {
        return Bank::Intesa;
    }

    public function supports(array $header): bool
    {
        return (new ColumnMap($header))->hasAll(self::REQUIRED_COLUMNS);
    }

    public function parse(array $header, array $rows): ParsedStatement
    {
        $columns = new ColumnMap($header);
        $rowsTotal = 0;
        $transactions = [];

        foreach ($rows as $row) {
            // Intesa exports a single date column: it is used as both booking and operation date.
            $date = $columns->date($row, 'data');
            $amount = $columns->amount($row, 'importo');

            if ($date === null || $amount === null) {
                continue;
            }

            $rowsTotal++;

            // Pending movements change description once booked: import them only when booked.
            if (mb_strtoupper($columns->string($row, 'contabilizzazione')) === 'NON CONTABILIZZATO') {
                continue;
            }

            $operation = $columns->string($row, 'operazione');
            $details = $columns->string($row, 'dettagli');
            $account = $columns->string($row, 'conto o carta');
            $isPrepaidCard = $account !== '' && ! str_starts_with(mb_strtolower($account), 'conto');
            [$kind, $merchant] = $this->classify($operation, $details, $amount, $isPrepaidCard);
            $category = $columns->string($row, 'categoria');

            $transactions[] = new ParsedTransaction(
                kind: $kind,
                bookingDate: $date,
                operationDate: $date,
                amount: $amount,
                rawDescription: $details !== '' ? $details : $operation,
                merchantLabel: $merchant,
                paymentInstrument: match (true) {
                    $isPrepaidCard => $this->cardName($account),
                    $kind === TransactionKind::Card => 'Carta di debito',
                    default => null,
                },
                bankCategory: $category !== '' ? $category : null,
            );
        }

        return new ParsedStatement(Bank::Intesa, $rowsTotal, $transactions);
    }

    /**
     * @return array{TransactionKind, string}
     */
    private function classify(string $operation, string $details, float $amount, bool $isPrepaidCard): array
    {
        if (preg_match('/^Addebito Diretto Disposto A Favore Di\s+(.+?)(?:\s+MANDATO\b.*)?$/i', $operation, $m) === 1) {
            return [TransactionKind::DirectDebit, $m[1]];
        }

        if (preg_match('/^Bonifico (?:Istantaneo )?Da .+? A Favore Di\s+(.+)$/i', $operation, $m) === 1) {
            return [TransactionKind::TransferOut, $m[1]];
        }

        if (preg_match('/^Bonifico (?:Istantaneo )?Disposto Da\s+(.+)$/i', $operation, $m) === 1) {
            return [TransactionKind::TransferIn, $m[1]];
        }

        if (preg_match('/^Bonifico In Entrata\s*(.*)$/i', $details, $m) === 1) {
            return [TransactionKind::TransferIn, trim($m[1]) !== '' ? trim($m[1]) : $operation];
        }

        if (preg_match('/^Bonifico/i', $operation) === 1) {
            return [$amount > 0 ? TransactionKind::TransferIn : TransactionKind::TransferOut, $operation];
        }

        $isCardPayment = ! preg_match('/^canone carta/i', $operation)
            && ($isPrepaidCard || preg_match(self::CARD_DETAILS_PATTERN, $details) === 1);

        return [$isCardPayment ? TransactionKind::Card : TransactionKind::Other, $operation];
    }

    /**
     * "SUPERFLASH ****5342..." → "SUPERFLASH": never keep card digits.
     */
    private function cardName(string $account): string
    {
        $name = trim((string) preg_replace('/[\d*xX\[\]]{4,}.*$|\[CARTA\].*$/u', '', $account));

        return $name !== '' ? $name : 'Carta prepagata';
    }
}
