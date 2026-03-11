<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->integer('year');
            $table->integer('month');
            $table->decimal('declared_balance', 12, 2);
            $table->decimal('calculated_balance', 12, 2);
            $table->decimal('adjustment', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
            $table->index(['year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliations');
    }
};
