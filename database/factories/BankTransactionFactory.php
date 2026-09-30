<?php

namespace Database\Factories;

use App\Enums\TransactionKind;
use App\Enums\TransactionStatus;
use App\Models\BankImport;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<BankTransaction>
 */
class BankTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $merchant = strtoupper(fake()->company());

        return [
            'bank_import_id' => BankImport::factory(),
            'fingerprint' => Str::random(40),
            'kind' => TransactionKind::Card,
            'accounting_date' => now()->startOfMonth(),
            'operation_date' => now()->startOfMonth(),
            'booking_date' => null,
            'amount' => -fake()->randomFloat(2, 1, 200),
            'raw_description' => "Pagamento presso {$merchant}",
            'merchant_key' => $merchant,
            'merchant_label' => $merchant,
            'payment_instrument' => null,
            'bank_category' => null,
            'category_id' => null,
            'categorization_source' => null,
            'confidence' => null,
            'status' => TransactionStatus::ToReview,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => ['status' => TransactionStatus::Confirmed]);
    }
}
