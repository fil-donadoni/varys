<?php

namespace App\Services\BankImport\Llm;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Local model through Ollama: nothing leaves the machine.
 */
class OllamaMerchantCategorizer implements MerchantCategorizer
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
    ) {}

    public function name(): string
    {
        return "Ollama locale ({$this->model})";
    }

    public function unavailableReason(): ?string
    {
        return null;
    }

    public function categorize(array $merchants, array $categories): array
    {
        if ($merchants === []) {
            return [];
        }

        try {
            $response = Http::timeout(300)->post(rtrim($this->url, '/').'/api/chat', [
                'model' => $this->model,
                'stream' => false,
                'format' => CategorizationPrompt::schema($categories),
                'options' => ['temperature' => 0],
                'messages' => [
                    ['role' => 'system', 'content' => CategorizationPrompt::SYSTEM],
                    ['role' => 'user', 'content' => CategorizationPrompt::userMessage($merchants, $categories)],
                ],
            ]);
        } catch (ConnectionException) {
            throw new CategorizerException("Ollama non risponde su {$this->url}: è avviato?");
        }

        if ($response->failed()) {
            throw new CategorizerException("Errore di Ollama ({$response->status()}): ".$response->json('error', ''));
        }

        return CategorizationPrompt::parse((string) $response->json('message.content', ''), $merchants, $categories);
    }
}
