<?php

use App\Services\ActualItemBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actual_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('description');
            $table->decimal('amount', 12, 2); // positive = raises the category actual (expense spent, income received)
            $table->timestamps();

            $table->index(['category_id', 'date']);
        });

        // Manual amounts were a single number per month: keep them as one item each.
        app(ActualItemBackfill::class)->fromManualAmounts();
    }

    public function down(): void
    {
        Schema::dropIfExists('actual_items');
    }
};
