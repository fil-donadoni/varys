<?php

namespace Database\Seeders;

use App\Models\ActualItem;
use App\Models\Category;
use Illuminate\Database\Seeder;

/**
 * Demo manual items for the current month: php artisan db:seed --class=ActualItemSeeder
 */
class ActualItemSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Category::query()->inRandomOrder()->limit(3)->get() as $category) {
            ActualItem::factory()->count(2)->create(['category_id' => $category->id]);
        }
    }
}
