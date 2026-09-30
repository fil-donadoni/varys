<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The ING monthly credit card charge goes to "Telefono, TV e Internet" when that category exists.
     */
    public function up(): void
    {
        $categoryId = DB::table('categories')->where('name', 'Telefono, TV e Internet')->value('id');

        if ($categoryId === null) {
            return;
        }

        DB::table('merchant_rules')->insertOrIgnore([
            'match_type' => 'exact',
            'pattern' => 'CARTA DI CREDITO ING',
            'category_id' => $categoryId,
            'always_ask' => false,
            'times_confirmed' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('merchant_rules')->where('match_type', 'exact')->where('pattern', 'CARTA DI CREDITO ING')->delete();
    }
};
