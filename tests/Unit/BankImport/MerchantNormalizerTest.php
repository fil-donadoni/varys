<?php

use App\Services\BankImport\MerchantNormalizer;

it('normalizes merchant labels', function (string $label, string $expected): void {
    expect((new MerchantNormalizer)->normalize($label))->toBe($expected);
})->with([
    ['AMAZON* ZG3658CU4', 'AMAZON'],
    ['AMZN Mktp IT*ZC8XJ0HG4', 'AMAZON'],
    ['WWW.AMAZON.* CI3PN3LQ5', 'AMAZON'],
    ['Amazon.it*VL8AN2S95', 'AMAZON'],
    ['Amazon Prime', 'AMAZON PRIME'],
    ['IPER MAGENTA NCR - Transazione C-less', 'IPER MAGENTA NCR'],
    ['IPER STATION MAGENTA Corso', 'IPER STATION MAGENTA'],
    ['THE KITCHEN - MAGENTA', 'THE KITCHEN'],
    ['The Kitchen Via Trento 1 Ca', 'THE KITCHEN'],
    ['LIDL 1429', 'LIDL'],
    ['Bar Tabacchi Zara 58 Mi', 'BAR TABACCHI ZARA MI'],
    ['Tesla_IT 18885183752', 'TESLA_IT'],
    ['Sqsp* Domain#219733428', 'SQSP* DOMAIN'],
    ['Google CLOUD 9HMGB6 Milan', 'GOOGLE CLOUD MILAN'],
    ['PAYPAL *BILLETTO Z2108', 'PAYPAL *BILLETTO'],
    ['PAYPAL *lorenzo.barba9', 'PAYPAL *LORENZO.BARBA9'],
    ['46102 Druogno Via Domo', 'DRUOGNO'],
    ['Q8 MILANO', 'Q8 MILANO'],
]);
