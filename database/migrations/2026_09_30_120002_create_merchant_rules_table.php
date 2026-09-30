<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('match_type');
            $table->string('pattern');
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->boolean('always_ask')->default(false);
            $table->unsignedInteger('times_confirmed')->default(0);
            $table->timestamps();

            $table->unique(['match_type', 'pattern']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_rules');
    }
};
