<?php

use App\Enums\Bank;
use App\Enums\TransactionKind;
use App\Services\BankImport\Parsers\IngParser;
use App\Services\BankImport\SpreadsheetReader;
use App\Services\BankImport\StatementParser;
use App\Services\BankImport\UnsupportedStatementException;
use Tests\Support\BankStatementFixtures;

function statementParser(): StatementParser
{
    return new StatementParser(new SpreadsheetReader);
}

function summarize(array $transactions): array
{
    return array_map(fn ($t) => [$t->kind, $t->merchantLabel, $t->amount], $transactions);
}

it('parses every ING movement with kind and counterparty', function (): void {
    $statement = statementParser()->parseFile(BankStatementFixtures::ing());

    expect($statement->bank)->toBe(Bank::Ing)
        ->and($statement->rowsTotal)->toBe(8)
        ->and(summarize($statement->transactions))->toBe([
            [TransactionKind::Card, 'AMAZON* ZG3658CU4', -29.99],
            [TransactionKind::DirectDebit, 'RAPPORTI INTERNI SPORTELLO 00702', -780.76],
            [TransactionKind::Card, 'IPER MAGENTA NCR', -116.49],
            [TransactionKind::Card, IngParser::CREDIT_CARD_MERCHANT, -29.9],
            [TransactionKind::TransferIn, 'PERSONA_2', 500.0],
            [TransactionKind::TransferOut, 'Lacos Group srl', -329.4],
            [TransactionKind::Card, 'WWW.AMAZON.* CI3PN3LQ5', -33.85],
            [TransactionKind::Other, 'Interessi E Competenze', -0.88],
        ]);

    $amazon = $statement->transactions[0];

    expect($amazon->operationDate->toDateString())->toBe('2025-12-30')
        ->and($amazon->bookingDate->toDateString())->toBe('2026-01-01')
        ->and($amazon->paymentInstrument)->toBe('Mastercard')
        ->and($statement->transactions[3]->paymentInstrument)->toBe('Carta di credito');
});

it('parses every booked Intesa movement with kind and counterparty', function (): void {
    $statement = statementParser()->parseFile(BankStatementFixtures::intesa());

    expect($statement->bank)->toBe(Bank::Intesa)
        ->and($statement->rowsTotal)->toBe(11)
        ->and(summarize($statement->transactions))->toBe([
            [TransactionKind::Other, 'Imposta Di Bollo E/c E Rendiconto', -8.4],
            [TransactionKind::Card, 'Farmacia Della Basili. Magenta', -24.21],
            [TransactionKind::Other, 'Canone Carta Di Debito', -1.5],
            [TransactionKind::TransferIn, 'DM GROUP S.R.L.', 4750.0],
            [TransactionKind::Card, 'Paypal *aruba Spa', -12.08],
            [TransactionKind::Card, 'IPER STATION MAGENTA Corso', -61.67],
            [TransactionKind::Card, 'Antico Vinaio Italia Srl', -13.0],
            [TransactionKind::DirectDebit, 'TELEPASS SPA', -32.8],
            [TransactionKind::TransferIn, 'PERSONA_5', 67.0],
            [TransactionKind::Card, 'Zalando Payments', 20.5],
        ]);

    [, $pharmacy, , , $paypal] = $statement->transactions;

    expect($pharmacy->bookingDate->toDateString())->toBe('2026-03-31')
        ->and($pharmacy->bankCategory)->toBe('Farmacia')
        ->and($pharmacy->paymentInstrument)->toBe('Carta di debito')
        ->and($paypal->paymentInstrument)->toBe('SUPERFLASH');
});

it('respects the bank chosen by the user', function (): void {
    statementParser()->parseFile(BankStatementFixtures::ing(), Bank::Intesa);
})->throws(UnsupportedStatementException::class, 'Il file non sembra un export di Intesa Sanpaolo.');

it('rejects unknown formats', function (): void {
    statementParser()->parseFile(BankStatementFixtures::xlsx([['Foo', 'Bar'], ['a', 'b']]));
})->throws(UnsupportedStatementException::class);

it('reads Italian formatted string amounts and dates', function (): void {
    $statement = statementParser()->parseRows([
        ['DATA CONTABILE', 'DATA VALUTA', 'CAUSALE', 'DESCRIZIONE OPERAZIONE', 'IMPORTO IN EURO'],
        ['03/01/2026', '02/01/2026', 'Pagamento Carta', 'Operazione Mastercard del 02/01/2026 alle ore 10:00 presso BAR ROMA', '-1.234,50'],
    ]);

    expect($statement->transactions[0]->amount)->toBe(-1234.5)
        ->and($statement->transactions[0]->operationDate->toDateString())->toBe('2026-01-02')
        ->and($statement->transactions[0]->bookingDate->toDateString())->toBe('2026-01-03');
});
