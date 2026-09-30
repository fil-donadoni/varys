<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('budget_entries', function (Blueprint $table): void {
            $table->boolean('is_invoiced')->default(false)->after('amount');
        });

        Schema::table('budget_entry_items', function (Blueprint $table): void {
            $table->boolean('is_invoiced')->default(false)->after('amount');
        });

        // The category flag used to be the only source of truth: copy it onto every existing line.
        DB::statement(<<<'SQL'
            UPDATE budget_entries
            SET is_invoiced = categories.is_invoiced
            FROM categories
            WHERE categories.id = budget_entries.category_id
              AND categories.type = 'income'
        SQL);

        DB::statement(<<<'SQL'
            UPDATE budget_entry_items
            SET is_invoiced = budget_entries.is_invoiced
            FROM budget_entries
            WHERE budget_entries.id = budget_entry_items.budget_entry_id
        SQL);
    }

    public function down(): void
    {
        Schema::table('budget_entry_items', function (Blueprint $table): void {
            $table->dropColumn('is_invoiced');
        });

        Schema::table('budget_entries', function (Blueprint $table): void {
            $table->dropColumn('is_invoiced');
        });
    }
};
