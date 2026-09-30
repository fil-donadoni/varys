<?php

namespace App\Services\BankImport\Parsers;

use App\Enums\Bank;
use App\Services\BankImport\ParsedCardExpense;
use App\Services\BankImport\ParsedStatement;
use Carbon\CarbonImmutable;

class IngParser implements BankStatementParser
{
    public const string CREDIT_CARD_MERCHANT = 'Carta di credito ING';

    private const array REQUIRED_COLUMNS = ['data contabile', 'data valuta', 'causale', 'descrizione operazione', 'importo in euro'];

    public function bank(): Bank
    {
        return Bank::Ing;
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
            $bookingDate = $columns->date($row, 'data contabile');
            $amount = $columns->amount($row, 'importo in euro');

            if ($bookingDate === null || $amount === null) {
                continue;
            }

            $rowsTotal++;
            $expense = $this->parseCardExpense(
                causale: mb_strtolower($columns->string($row, 'causale')),
                description: $columns->string($row, 'descrizione operazione'),
                bookingDate: $bookingDate,
                valueDate: $columns->date($row, 'data valuta'),
                amount: $amount,
            );

            if ($expense !== null) {
                $expenses[] = $expense;
            }
        }

        return new ParsedStatement(Bank::Ing, $rowsTotal, $expenses);
    }

    private function parseCardExpense(string $causale, string $description, CarbonImmutable $bookingDate, ?CarbonImmutable $valueDate, float $amount): ?ParsedCardExpense
    {
        if ($causale === 'addebito carta di credito') {
            return new ParsedCardExpense(
                operationDate: $bookingDate,
                bookingDate: $bookingDate,
                amount: -$amount,
                rawDescription: $description,
                merchantLabel: self::CREDIT_CARD_MERCHANT,
                paymentInstrument: 'Carta di credito',
            );
        }

        if (! str_contains($causale, 'carta') || ! preg_match('/^(pagamento|storno|rimborso)/', $causale)) {
            return null;
        }

        $operationDate = preg_match('/Operazione \S+ del (\d{2})\/(\d{2})\/(\d{4})/i', $description, $m) === 1
            ? CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])
            : ($valueDate ?? $bookingDate);

        $merchant = preg_match('/\bpresso\s+(.+)$/i', $description, $p) === 1
            ? (string) preg_replace('/\s+-\s+Transazione C-less$/i', '', trim($p[1]))
            : $description;

        return new ParsedCardExpense(
            operationDate: $operationDate ?? $bookingDate,
            bookingDate: $bookingDate,
            amount: -$amount,
            rawDescription: $description,
            merchantLabel: $merchant,
            paymentInstrument: preg_match('/Operazione (\S+) del/i', $description, $i) === 1 ? $i[1] : null,
        );
    }
}
