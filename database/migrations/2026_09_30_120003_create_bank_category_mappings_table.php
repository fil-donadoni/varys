<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_category_mappings', function (Blueprint $table): void {
            $table->id();
            $table->string('bank');
            $table->string('bank_category');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['bank', 'bank_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_category_mappings');
    }
};
