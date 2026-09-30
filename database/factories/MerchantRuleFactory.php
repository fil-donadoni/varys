<?php

namespace Database\Factories;

use App\Enums\MerchantMatchType;
use App\Models\Category;
use App\Models\MerchantRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchantRule>
 */
class MerchantRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'match_type' => MerchantMatchType::Exact,
            'pattern' => strtoupper(fake()->unique()->company()),
            'category_id' => Category::factory()->expense(),
            'always_ask' => false,
            'times_confirmed' => 0,
        ];
    }

    public function keyword(string $pattern): static
    {
        return $this->state(fn (): array => ['match_type' => MerchantMatchType::Contains, 'pattern' => $pattern]);
    }
}
