<?php

namespace App\Providers;

use App\Services\BankImport\Llm\ClaudeMerchantCategorizer;
use App\Services\BankImport\Llm\MerchantCategorizer;
use App\Services\BankImport\Llm\NullMerchantCategorizer;
use App\Services\BankImport\Llm\OllamaMerchantCategorizer;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(MerchantCategorizer::class, fn (): MerchantCategorizer => match (config('bank_import.categorizer')) {
            'claude' => new ClaudeMerchantCategorizer(
                apiKey: config('bank_import.claude.api_key'),
                model: (string) config('bank_import.claude.model'),
                effort: (string) config('bank_import.claude.effort'),
            ),
            'ollama' => new OllamaMerchantCategorizer(
                url: (string) config('bank_import.ollama.url'),
                model: (string) config('bank_import.ollama.model'),
            ),
            default => new NullMerchantCategorizer,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
