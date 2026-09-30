<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_import_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint')->unique();
            $table->date('operation_date');
            $table->date('booking_date')->nullable();
            $table->decimal('amount', 12, 2); // positive = expense, negative = refund
            $table->text('raw_description');
            $table->string('merchant_key');
            $table->string('merchant_label');
            $table->string('payment_instrument')->nullable();
            $table->string('bank_category')->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('categorization_source')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->string('status');
            $table->timestamps();

            $table->index('merchant_key');
            $table->index(['status', 'category_id', 'operation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
