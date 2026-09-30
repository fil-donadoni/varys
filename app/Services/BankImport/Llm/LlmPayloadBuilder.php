<?php

namespace App\Services\BankImport\Llm;

use App\Enums\CategorizationSource;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use App\Models\Setting;
use App\Services\BankImport\PersonLikeMerchantDetector;
use App\Services\BankImport\StatementAnonymizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

/**
 * Decides what may leave the machine: distinct merchants still to categorize,
 * scrubbed again by the anonymizer. People and anything still carrying personal data stay local.
 */
class LlmPayloadBuilder
{
    public const string SKIP_PERSON = 'Sembra una persona';

    public const string SKIP_PERSONAL_DATA = 'Contiene dati personali';

    public function __construct(private readonly PersonLikeMerchantDetector $detector) {}

    /**
     * @return array{send: list<array{merchant_key: string, name: string, direction: string, bank_category: string|null, count: int}>, skipped: list<array{merchant_key: string, label: string, reason: string, count: int}>}
     */
    public function preview(BankImport $import): array
    {
        $anonymizer = new StatementAnonymizer(self::customMasks());
        $send = [];
        $skipped = [];

        foreach ($this->pendingGroups($import) as $merchantKey => $group) {
            /** @var BankTransaction $first */
            $first = $group->first();
            $count = $group->count();

            if ($this->detector->looksLikePerson($first->merchant_label, $first->kind)) {
                $skipped[] = ['merchant_key' => (string) $merchantKey, 'label' => $first->merchant_label, 'reason' => self::SKIP_PERSON, 'count' => $count];

                continue;
            }

            $name = $anonymizer->scrubText((string) $merchantKey);

            if (StatementAnonymizer::containsMaskedData($name)) {
                $skipped[] = ['merchant_key' => (string) $merchantKey, 'label' => $first->merchant_label, 'reason' => self::SKIP_PERSONAL_DATA, 'count' => $count];

                continue;
            }

            $send[] = [
                'merchant_key' => (string) $merchantKey,
                'name' => $name,
                'direction' => $group->sum(fn (BankTransaction $t): float => (float) $t->amount) > 0 ? 'entrata' : 'uscita',
                'bank_category' => $first->bank_category,
                'count' => $count,
            ];
        }

        return ['send' => $send, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    public static function customMasks(): array
    {
        $raw = (string) Setting::getValue('anonymize_masks', '');

        return array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $raw) ?: [])));
    }

    /**
     * Merchants still to review that were never sent: no category yet, or only the bank suggestion.
     *
     * @return SupportCollection<array-key, Collection<int, BankTransaction>>
     */
    private function pendingGroups(BankImport $import): SupportCollection
    {
        return $import->transactions()
            ->where('status', TransactionStatus::ToReview)
            ->where(fn ($q) => $q->whereNull('categorization_source')->orWhere('categorization_source', CategorizationSource::Bank))
            ->orderBy('merchant_key')
            ->get()
            ->groupBy('merchant_key');
    }
}
