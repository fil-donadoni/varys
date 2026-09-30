<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('actual_entries', function (Blueprint $table): void {
            $table->decimal('manual_amount', 12, 2)->default(0)->after('amount');
            $table->decimal('imported_amount', 12, 2)->default(0)->after('manual_amount');
        });

        // Existing entries were all typed by hand.
        DB::table('actual_entries')->update(['manual_amount' => DB::raw('amount')]);
    }

    public function down(): void
    {
        Schema::table('actual_entries', function (Blueprint $table): void {
            $table->dropColumn(['manual_amount', 'imported_amount']);
        });
    }
};
