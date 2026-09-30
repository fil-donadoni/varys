<?php

namespace App\Services\BankImport\Parsers;

use App\Enums\Bank;
use App\Services\BankImport\ParsedCardExpense;
use App\Services\BankImport\ParsedStatement;

class IntesaParser implements BankStatementParser
{
    private const array REQUIRED_COLUMNS = ['data', 'operazione', 'dettagli', 'conto o carta', 'categoria', 'importo'];

    private const string CARD_DETAILS_PATTERN = '/mediante la carta|carta n\.|pagamento su pos/i';

    private const string EXCLUDED_OPERATIONS_PATTERN = '/^(canone carta|bonifico|ricarica|giroconto|commissioni|imposta)/i';

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
        $expenses = [];

        foreach ($rows as $row) {
            $date = $columns->date($row, 'data');
            $amount = $columns->amount($row, 'importo');

            if ($date === null || $amount === null) {
                continue;
            }

            $rowsTotal++;

            $operation = $columns->string($row, 'operazione');
            $details = $columns->string($row, 'dettagli');
            $account = $columns->string($row, 'conto o carta');
            $isPrepaidCard = $account !== '' && ! str_starts_with(mb_strtolower($account), 'conto');
            $isBooked = mb_strtoupper($columns->string($row, 'contabilizzazione')) !== 'NON CONTABILIZZATO';

            if (! $isBooked
                || preg_match(self::EXCLUDED_OPERATIONS_PATTERN, $operation) === 1
                || (! $isPrepaidCard && preg_match(self::CARD_DETAILS_PATTERN, $details) !== 1)) {
                continue;
            }

            $category = $columns->string($row, 'categoria');

            $expenses[] = new ParsedCardExpense(
                operationDate: $date,
                bookingDate: null,
                amount: -$amount,
                rawDescription: $details !== '' ? $details : $operation,
                merchantLabel: $operation,
                paymentInstrument: $isPrepaidCard ? $this->cardName($account) : 'Carta di debito',
                bankCategory: $category !== '' ? $category : null,
            );
        }

        return new ParsedStatement(Bank::Intesa, $rowsTotal, $expenses);
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
