<?php

namespace App\Services\BankImport;

/**
 * Flags merchants that are likely private people: they are never sent to the LLM.
 */
class PersonLikeMerchantDetector
{
    public function looksLikePerson(string $label): bool
    {
        // PayPal to a user handle, e.g. "PAYPAL *mario.rossi9".
        if (preg_match('/PAYPAL\s*\*\s*([^\s]+)/i', $label, $m) === 1 && preg_match('/[.\d_]/', $m[1]) === 1) {
            return true;
        }

        // SumUp terminals named after a person, e.g. "Sum*Mario Rossi".
        if (preg_match('/^SUM\s*\*\s*\p{L}+\s+\p{L}+/iu', $label) === 1) {
            return true;
        }

        return preg_match('/\bSATISPAY\b/i', $label) === 1;
    }
}
