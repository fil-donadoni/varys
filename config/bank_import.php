<?php

return [

    /*
    | Which LLM resolves merchants the app cannot categorize locally: "claude", "ollama" or "none".
    | Only anonymized, distinct merchant names are sent: never amounts, dates or people.
    */
    'categorizer' => env('BANK_IMPORT_LLM', 'claude'),

    // Suggestions below this confidence stay "to review".
    'confidence_threshold' => (float) env('BANK_IMPORT_CONFIDENCE', 0.8),

    'batch_size' => 50,

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        // Needed only for API keys not scoped to a workspace (sent as the anthropic-workspace-id header).
        'workspace_id' => env('ANTHROPIC_WORKSPACE_ID'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'effort' => env('ANTHROPIC_EFFORT', 'low'),
    ],

    'ollama' => [
        'url' => env('OLLAMA_URL', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'qwen3:8b'),
    ],

];
