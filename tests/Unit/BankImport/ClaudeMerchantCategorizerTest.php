<?php

use Anthropic\Core\Exceptions\APIStatusException;
use App\Services\BankImport\Llm\ClaudeMerchantCategorizer;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

function apiError(int $status, string $type, string $message): APIStatusException
{
    return APIStatusException::from(
        new Request('POST', 'https://api.anthropic.com/v1/messages'),
        new Response($status, [], (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => $message]])),
    );
}

it('explains API keys that are not scoped to a workspace', function (): void {
    $message = ClaudeMerchantCategorizer::describe(apiError(400, 'invalid_request_error', 'This API key is not scoped to a workspace, so this request must include the anthropic-workspace-id header.'));

    expect($message)->toStartWith('La chiave API non è associata a un workspace: imposta ANTHROPIC_WORKSPACE_ID');
});

it('shows the API message instead of the bare error type', function (): void {
    expect(ClaudeMerchantCategorizer::describe(apiError(400, 'invalid_request_error', 'max_tokens: must be positive')))
        ->toBe('Errore di Claude (400): max_tokens: must be positive');
});

it('needs an API key', function (): void {
    expect((new ClaudeMerchantCategorizer(null, null, 'claude-opus-5-5', 'low'))->unavailableReason())->not->toBeNull()
        ->and((new ClaudeMerchantCategorizer('sk-test', 'wrkspc_1', 'claude-opus-5-5', 'low'))->unavailableReason())->toBeNull();
});
