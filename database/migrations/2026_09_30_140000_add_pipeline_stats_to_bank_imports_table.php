<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_imports', function (Blueprint $table): void {
            $table->json('anonymization_stats')->nullable();
            $table->json('llm_stats')->nullable();
            $table->timestamp('llm_ran_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_imports', function (Blueprint $table): void {
            $table->dropColumn(['anonymization_stats', 'llm_stats', 'llm_ran_at']);
        });
    }
};
