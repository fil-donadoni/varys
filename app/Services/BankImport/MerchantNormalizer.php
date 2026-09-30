<?php

namespace App\Services\BankImport;

/**
 * Turns a raw merchant label into a stable key used for memory rules and LLM input.
 * Strips transaction codes, addresses and glued date/time stamps.
 */
class MerchantNormalizer
{
    private const string ADDRESS_PATTERN = '/\s+(VIA|V\.LE|VIALE|CORSO|C\.SO|PIAZZA|P\.ZA|P\.ZZA|LARGO|STRADA|LOC\.|LOCALITA)(\s.*)?$/u';

    public function normalize(string $label): string
    {
        $value = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $label)));

        $value = (string) preg_replace('/\s+-\s+TRANSAZIONE C-LESS$/u', '', $value);

        if (preg_match('/^(AMZN|AMAZON|WWW\.AMAZON)/u', $value) === 1) {
            return str_contains($value, 'PRIME') ? 'AMAZON PRIME' : 'AMAZON';
        }

        // "S.p.A.", "S P A", "S.R.L." → "SPA", "SRL" so the same company gets one key.
        $value = (string) preg_replace('/\bS\.?\s?P\.?\s?A\b\.?/u', 'SPA', $value);
        $value = (string) preg_replace('/\bS\.?\s?R\.?\s?L\b\.?/u', 'SRL', $value);

        $value = (string) preg_replace('/\s+-\s+.*$/u', '', $value);
        $value = (string) preg_replace(self::ADDRESS_PATTERN, '', $value);
        $value = (string) preg_replace('/#\S*/u', '', $value);

        $tokens = array_filter(
            explode(' ', $value),
            fn (string $token): bool => $token !== '' && ! $this->isCodeToken($token),
        );

        $key = trim(implode(' ', $tokens), " \t-.,*/");

        return $key !== '' ? $key : mb_strtoupper(trim($label));
    }

    /**
     * Pure numbers, glued date/time stamps and long alphanumeric codes (e.g. ZG3658CU4).
     * Short mixed tokens like "Q8" are kept.
     */
    private function isCodeToken(string $token): bool
    {
        // "*handle" after a payment gateway (e.g. "PAYPAL *mario.rossi9") names the merchant.
        if (str_starts_with($token, '*') && preg_match('/\p{L}/u', $token) === 1) {
            return false;
        }

        if (preg_match('/^[\d\/.,:-]+$/', $token) === 1) {
            return true;
        }

        return mb_strlen($token) >= 5
            && preg_match('/\d/', $token) === 1
            && preg_match('/\p{L}/u', $token) === 1;
    }
}
