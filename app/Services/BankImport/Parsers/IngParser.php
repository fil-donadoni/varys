<?php

namespace App\Services\BankImport\Parsers;

use App\Enums\Bank;
use App\Enums\TransactionKind;
use App\Services\BankImport\ParsedStatement;
use App\Services\BankImport\ParsedTransaction;
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
        $transactions = [];

        foreach ($rows as $row) {
            $bookingDate = $columns->date($row, 'data contabile');
            $amount = $columns->amount($row, 'importo in euro');

            if ($bookingDate === null || $amount === null) {
                continue;
            }

            $transactions[] = $this->parseRow(
                causale: $columns->string($row, 'causale'),
                description: $columns->string($row, 'descrizione operazione'),
                bookingDate: $bookingDate,
                valueDate: $columns->date($row, 'data valuta') ?? $bookingDate,
                amount: $amount,
            );
        }

        return new ParsedStatement(Bank::Ing, count($transactions), $transactions);
    }

    private function parseRow(string $causale, string $description, CarbonImmutable $bookingDate, CarbonImmutable $valueDate, float $amount): ParsedTransaction
    {
        $type = mb_strtolower($causale);

        [$kind, $merchant, $operationDate, $instrument] = match (true) {
            $type === 'addebito carta di credito' => [TransactionKind::Card, self::CREDIT_CARD_MERCHANT, $bookingDate, 'Carta di credito'],
            str_contains($type, 'carta') => [TransactionKind::Card, $this->cardMerchant($description), $this->cardOperationDate($description) ?? $valueDate, $this->cardCircuit($description)],
            $type === 'addebito diretto' => [TransactionKind::DirectDebit, $this->match('/Creditor id\.\s+\S+\s+(.+?)\s+Id Mandato/i', $description) ?? $causale, $valueDate, null],
            str_contains($type, 'bonifico') && $amount > 0 => [TransactionKind::TransferIn, $this->match('/Anagrafica Ordinante\s+(.+?)(?:\s+Note:.*)?$/i', $description) ?? $causale, $valueDate, null],
            str_contains($type, 'bonifico') => [TransactionKind::TransferOut, $this->match('/A FAVORE DI\s+(.+?)(?:\s+C\. BENEF\..*|\s+NOTE:.*)?$/i', $description) ?? $causale, $valueDate, null],
            default => [TransactionKind::Other, $causale, $valueDate, null],
        };

        return new ParsedTransaction(
            kind: $kind,
            bookingDate: $bookingDate,
            operationDate: $operationDate,
            amount: $amount,
            rawDescription: $description,
            merchantLabel: $merchant,
            paymentInstrument: $instrument,
        );
    }

    private function cardMerchant(string $description): string
    {
        $merchant = $this->match('/\bpresso\s+(.+)$/i', $description);

        return $merchant !== null ? (string) preg_replace('/\s+-\s+Transazione C-less$/i', '', $merchant) : $description;
    }

    private function cardOperationDate(string $description): ?CarbonImmutable
    {
        if (preg_match('/Operazione \S+ del (\d{2})\/(\d{2})\/(\d{4})/i', $description, $m) !== 1) {
            return null;
        }

        return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1]);
    }

    private function cardCircuit(string $description): ?string
    {
        return $this->match('/Operazione (\S+) del/i', $description);
    }

    private function match(string $pattern, string $subject): ?string
    {
        return preg_match($pattern, $subject, $m) === 1 && trim($m[1]) !== '' ? trim($m[1]) : null;
    }
}
