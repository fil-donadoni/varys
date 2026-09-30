<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_entry_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('budget_entry_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->smallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['budget_entry_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_entry_items');
    }
};
