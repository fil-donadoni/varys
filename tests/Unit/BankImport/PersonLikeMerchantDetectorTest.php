<?php

use App\Services\BankImport\PersonLikeMerchantDetector;

it('detects merchants that look like people', function (string $label, bool $expected): void {
    expect((new PersonLikeMerchantDetector)->looksLikePerson($label))->toBe($expected);
})->with([
    ['PAYPAL *lorenzo.barba9', true],
    ['PAYPAL *v.daruos', true],
    ['PAYPAL *dado.rock', true],
    ['Sum*Cristina Locanto CORBETTA', true],
    ['Sum*Osteopata Anita Sa', true],
    ['SATISPAY MARIO', true],
    ['Paypal *aruba Spa', false],
    ['Paypal *playstation', false],
    ['PAYPAL *BILLETTO Z2108', false],
    ['IPER MAGENTA NCR', false],
    ['Sum*Bar', false],
]);
