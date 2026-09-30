<?php

use App\Services\BankImport\StatementAnonymizer;

function anonymizer(array $masks = []): StatementAnonymizer
{
    return new StatementAnonymizer(customMasks: $masks);
}

it('detects header rows', function (): void {
    $anonymizer = anonymizer();

    expect($anonymizer->isHeaderRow(['Data contabile', 'Data valuta', 'Descrizione', 'Importo']))->toBeTrue()
        ->and($anonymizer->isHeaderRow(['Intestatario:', 'Mario Rossi']))->toBeFalse();
});

it('keeps dates and amounts untouched', function (string $value): void {
    expect(anonymizer()->anonymizeValue($value))->toBe($value);
})->with(['25/12/2025', '25.12.25', '2025-12-25T10:30:00', '-1.234,56', '1234.56', '€ 1.000,00', '20251225123456']);

it('keeps card payment descriptions untouched', function (string $description): void {
    expect(anonymizer()->scrubText($description))->toBe($description);
})->with([
    'Operazione Mastercard del 30/12/2025 alle ore 11:34 Div=EUR Importo in Euro=29.99 presso AMAZON* ZG3658CU4',
    'Addebito SDD CORE Scad. 02/01/2026 Imp. 780.76 Rif. PAGAMENTO RATA FIN. 080000044512227 SCADENZA 20260101',
    'Pagamento Carta n. 4567 AMAZON EU SARL rif 123456789',
    'PAGAMENTO POS ESSELUNGA MILANO',
]);

it('masks sensitive identifiers in free text', function (): void {
    $result = anonymizer()->scrubText(
        'Carta 4111 1111 1111 1111 IBAN IT60X0542811101000000123456 CF RSSMRA80A01H501U mail mario@example.com'
    );

    expect($result)->toBe('Carta [CARTA] IBAN [IBAN] CF [CF] mail [EMAIL]');
});

it('masks bank-masked card numbers', function (string $input, string $expected): void {
    expect(anonymizer()->scrubText($input))->toBe($expected);
})->with([
    ['Mediante La Carta 5167 Xxxx Xxxx Xx60 Presso Bar Roma', 'Mediante La Carta [CARTA] Presso Bar Roma'],
    ['BAR ROMA 24/030820 Carta N.5167 XXXX XXXX XX60ABI 09514', 'BAR ROMA 24/030820 Carta N.[CARTA]ABI 09514'],
    ['Mese Di Marzo Carta N. 5167xxxxxxxxxx60', 'Mese Di Marzo Carta N. [CARTA]'],
    ['SUPERFLASH ****534207000006', 'SUPERFLASH [CARTA]'],
    ['con Carta xxxxxxxxxxxx1234 Div=EUR', 'con Carta [CARTA] Div=EUR'],
]);

it('masks fiscal codes embedded in longer codes', function (): void {
    expect(anonymizer()->scrubText('Mandato CVCK64000001RSSMRA80A01H501U e mandato cvck64000001rssmra80a01h501u'))
        ->toBe('Mandato CVCK64000001[CF] e mandato cvck64000001[CF]');
});

it('masks account numbers', function (): void {
    expect(anonymizer()->scrubText('Conto 1000/00012345'))->toBe('Conto [CONTO]');
});

it('masks ibans written in groups', function (): void {
    expect(anonymizer()->scrubText('IBAN IT60 X054 2811 1010 0000 0123 456 causale affitto'))->toBe('IBAN [IBAN] causale affitto');
});

it('keeps merchant names', function (): void {
    expect(anonymizer()->scrubText('PAGAMENTO POS ESSELUNGA MILANO'))->toBe('PAGAMENTO POS ESSELUNGA MILANO');
});

it('masks people after transfer keywords consistently', function (): void {
    $anonymizer = anonymizer();

    expect($anonymizer->scrubText('Bonifico ricevuto da Mario Rossi causale affitto'))->toBe('Bonifico ricevuto da PERSONA_1 causale affitto')
        ->and($anonymizer->scrubText('BONIFICO A VOSTRO FAVORE DA MARIO ROSSI'))->toBe('BONIFICO A VOSTRO FAVORE DA PERSONA_1')
        ->and($anonymizer->scrubText('Ordinante: Giulia Bianchi Beneficiario: Luca Verdi'))->toBe('Ordinante: PERSONA_2 Beneficiario: PERSONA_3');
});

it('keeps companies after transfer keywords', function (): void {
    expect(anonymizer()->scrubText('Bonifico a favore di Enel Energia SpA'))->toBe('Bonifico a favore di Enel Energia SpA');
});

it('masks whole cells in person columns', function (): void {
    $anonymizer = anonymizer();

    expect($anonymizer->isPersonColumn('Beneficiario'))->toBeTrue()
        ->and($anonymizer->anonymizeValue('Mario Rossi', isPersonColumn: true))->toBe('PERSONA_1');
});

it('masks first names next to a custom mask', function (): void {
    expect(anonymizer(['Rossi'])->scrubText('Anagrafica Ordinante ROSSI MARIO Note: affitto'))
        ->toBe('Anagrafica Ordinante PERSONA_1 Note: affitto');
});

it('applies custom masks', function (): void {
    expect(anonymizer(['Mario Rossi'])->scrubText('Addebito SDD per MARIO ROSSI'))->toBe('Addebito SDD per [NOME]');
});

it('masks preamble values but keeps labels', function (): void {
    $anonymizer = anonymizer();

    expect($anonymizer->anonymizePreambleCell('Intestatario:'))->toBe('Intestatario:')
        ->and($anonymizer->anonymizePreambleCell('Mario Rossi'))->toBe(StatementAnonymizer::PREAMBLE_PLACEHOLDER)
        ->and($anonymizer->anonymizePreambleCell('Intestatario: Mario Rossi'))->toBe(StatementAnonymizer::PREAMBLE_PLACEHOLDER);
});
