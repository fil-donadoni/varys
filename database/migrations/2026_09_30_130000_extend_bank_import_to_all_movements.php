<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank import now covers every movement (not only card expenses):
 * amounts keep the bank sign and the accounting month follows the booking date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->string('kind')->default('card');
            $table->date('accounting_date')->nullable();
            $table->dropIndex(['status', 'category_id', 'operation_date']);
            $table->index(['status', 'category_id', 'accounting_date']);
        });

        // Old rows stored expenses as positive amounts.
        DB::table('bank_transactions')->update([
            'amount' => DB::raw('-amount'),
            'accounting_date' => DB::raw('COALESCE(booking_date, operation_date)'),
        ]);

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->date('accounting_date')->nullable(false)->change();
            $table->string('kind')->default(null)->change();
        });

        Schema::table('bank_imports', function (Blueprint $table): void {
            $table->renameColumn('rows_card_expenses', 'rows_imported');
        });

        Schema::table('merchant_rules', function (Blueprint $table): void {
            $table->foreignId('category_id')->nullable()->change();
            $table->boolean('exclude')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('merchant_rules', function (Blueprint $table): void {
            $table->dropColumn('exclude');
        });

        Schema::table('bank_imports', function (Blueprint $table): void {
            $table->renameColumn('rows_imported', 'rows_card_expenses');
        });

        DB::table('bank_transactions')->update(['amount' => DB::raw('-amount')]);

        Schema::table('bank_transactions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'category_id', 'accounting_date']);
            $table->index(['status', 'category_id', 'operation_date']);
            $table->dropColumn(['kind', 'accounting_date']);
        });
    }
};
