<?php

namespace App\Services\BankImport\Llm;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Messages\OutputConfig\Effort;
use Anthropic\Messages\TextBlock;

class ClaudeMerchantCategorizer implements MerchantCategorizer
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $effort,
    ) {}

    public function name(): string
    {
        return "Claude ({$this->model})";
    }

    public function unavailableReason(): ?string
    {
        return blank($this->apiKey) ? 'Imposta ANTHROPIC_API_KEY nel file .env per usare Claude.' : null;
    }

    public function categorize(array $merchants, array $categories): array
    {
        if ($merchants === []) {
            return [];
        }

        try {
            $message = (new Client(apiKey: $this->apiKey))->messages->create(
                model: $this->model,
                maxTokens: 16000,
                system: CategorizationPrompt::SYSTEM,
                messages: [['role' => 'user', 'content' => CategorizationPrompt::userMessage($merchants, $categories)]],
                outputConfig: [
                    'effort' => Effort::from($this->effort),
                    'format' => ['type' => 'json_schema', 'schema' => CategorizationPrompt::schema($categories)],
                ],
            );
        } catch (AuthenticationException) {
            throw new CategorizerException('Chiave API di Claude non valida.');
        } catch (RateLimitException) {
            throw new CategorizerException('Troppe richieste a Claude: riprova tra qualche minuto.');
        } catch (APIConnectionException) {
            throw new CategorizerException('Impossibile contattare Claude: controlla la connessione.');
        } catch (APIStatusException $e) {
            throw new CategorizerException('Errore di Claude: '.($e->type->value ?? $e->getMessage()));
        }

        if ($message->stopReason === 'refusal') {
            throw new CategorizerException('Claude ha rifiutato la richiesta: categorizza questi esercenti a mano.');
        }

        if ($message->stopReason === 'max_tokens') {
            throw new CategorizerException('Risposta di Claude troncata: riprova con meno esercenti.');
        }

        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                return CategorizationPrompt::parse($block->text, $merchants, $categories);
            }
        }

        throw new CategorizerException('Claude non ha restituito una risposta.');
    }
}
