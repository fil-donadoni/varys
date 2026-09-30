<?php

namespace Database\Seeders;

use App\Enums\MerchantMatchType;
use App\Models\Category;
use App\Models\MerchantRule;
use Illuminate\Database\Seeder;

class MerchantRuleSeeder extends Seeder
{
    public function run(): void
    {
        $keywords = [
            'Spesa casa' => ['ESSELUNGA', 'LIDL', 'IPER', 'TIGROS', 'CONAD', 'COOP', 'CARREFOUR'],
            'Pranzi/cene' => ['RISTORANTE', 'PIZZERIA', 'TRATTORIA', 'OSTERIA', 'BAR ', 'CAFE'],
            'Benzina' => ['ENI ', 'Q8', 'ESSO', 'TAMOIL'],
            'Telepass' => ['TELEPASS'],
        ];

        foreach ($keywords as $categoryName => $patterns) {
            $category = Category::query()->where('name', $categoryName)->first();

            if ($category === null) {
                continue;
            }

            foreach ($patterns as $pattern) {
                MerchantRule::query()->firstOrCreate(
                    ['match_type' => MerchantMatchType::Contains, 'pattern' => $pattern],
                    ['category_id' => $category->id],
                );
            }
        }
    }
}
