<?php

use App\Enums\Bank;
use App\Services\BankImport\Parsers\IngParser;
use App\Services\BankImport\SpreadsheetReader;
use App\Services\BankImport\StatementParser;
use App\Services\BankImport\UnsupportedStatementException;
use Tests\Support\BankStatementFixtures;

function statementParser(): StatementParser
{
    return new StatementParser(new SpreadsheetReader);
}

it('parses ING card expenses only', function (): void {
    $statement = statementParser()->parseFile(BankStatementFixtures::ing());

    expect($statement->bank)->toBe(Bank::Ing)
        ->and($statement->rowsTotal)->toBe(8)
        ->and($statement->expenses)->toHaveCount(4);

    [$amazon, $iper, $creditCard, $amazonWww] = $statement->expenses;

    expect($amazon->operationDate->toDateString())->toBe('2025-12-30')
        ->and($amazon->bookingDate?->toDateString())->toBe('2026-01-01')
        ->and($amazon->amount)->toBe(29.99)
        ->and($amazon->merchantLabel)->toBe('AMAZON* ZG3658CU4')
        ->and($amazon->paymentInstrument)->toBe('Mastercard')
        ->and($iper->merchantLabel)->toBe('IPER MAGENTA NCR')
        ->and($iper->operationDate->toDateString())->toBe('2026-01-04')
        ->and($creditCard->merchantLabel)->toBe(IngParser::CREDIT_CARD_MERCHANT)
        ->and($creditCard->operationDate->toDateString())->toBe('2026-01-10')
        ->and($creditCard->amount)->toBe(29.9)
        ->and($amazonWww->merchantLabel)->toBe('WWW.AMAZON.* CI3PN3LQ5');
});

it('parses Intesa card expenses only', function (): void {
    $statement = statementParser()->parseFile(BankStatementFixtures::intesa());

    expect($statement->bank)->toBe(Bank::Intesa)
        ->and($statement->rowsTotal)->toBe(11)
        ->and(array_map(fn ($e) => $e->merchantLabel, $statement->expenses))->toBe([
            'Farmacia Della Basili. Magenta',
            'Paypal *aruba Spa',
            'IPER STATION MAGENTA Corso',
            'Antico Vinaio Italia Srl',
            'Zalando Payments',
        ]);

    [$pharmacy, $paypal, , , $refund] = $statement->expenses;

    expect($pharmacy->amount)->toBe(24.21)
        ->and($pharmacy->operationDate->toDateString())->toBe('2026-03-31')
        ->and($pharmacy->bankCategory)->toBe('Farmacia')
        ->and($pharmacy->paymentInstrument)->toBe('Carta di debito')
        ->and($paypal->paymentInstrument)->toBe('SUPERFLASH')
        ->and($refund->amount)->toBe(-20.5);
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

    expect($statement->expenses[0]->amount)->toBe(1234.5)
        ->and($statement->expenses[0]->operationDate->toDateString())->toBe('2026-01-02');
});
