<?php

namespace App\Services\BankImport;

use App\Enums\TransactionKind;

/**
 * Flags merchants/counterparties that are likely private people: they are never sent to the LLM.
 */
class PersonLikeMerchantDetector
{
    private const string ORGANIZATION_PATTERN = '/\b(S\.?\s?R\.?\s?L|S\.?\s?P\.?\s?A|S\.?\s?N\.?\s?C|S\.?\s?A\.?\s?S|SCARL|COOP\w*|ONLUS|ASSOCIAZIONE|CULTURALE|FONDAZIONE|BANCA|BANK|GMBH|LTD|INC|GROUP|ENTE|COMUNE|AGENZIA|SPORTELLO|ASSICURAZIONI|UNIVERSITA|SCUOLA|STUDIO)\b/iu';

    public function looksLikePerson(string $label, TransactionKind $kind = TransactionKind::Card): bool
    {
        // Transfers are mostly between people: treat as person unless it is clearly an organization.
        if ($kind->isTransfer()) {
            return preg_match(self::ORGANIZATION_PATTERN, $label) !== 1;
        }

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
