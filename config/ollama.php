<?php

return [

    // Turn the AI on or off without touching code.
    'enabled' => env('OLLAMA_ENABLED', false),

    // OpenAI-compatible endpoint of the local Ollama server. No billing involved.
    'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434/v1'),

    // Ollama ignores the key, but OpenAI-style clients expect one.
    'api_key' => env('OLLAMA_API_KEY', 'ollama'),

    // Never hardcode the model: change it in .env.
    'model' => env('OLLAMA_MODEL', 'qwen2.5:3b'),

    // Seconds to wait for the model before giving up.
    'timeout' => (int) env('OLLAMA_TIMEOUT', 120),

    // Seconds to wait for the server to accept the connection.
    'connect_timeout' => (int) env('OLLAMA_CONNECT_TIMEOUT', 3),

    // Most tokens the model may write for a long answer.
    'max_tokens' => (int) env('OLLAMA_MAX_TOKENS', 220),

    // The router only has to name an intent, so it needs very few tokens.
    'router_tokens' => (int) env('OLLAMA_ROUTER_TOKENS', 90),

    // Shown to users when the AI cannot answer. It never contains technical details.
    'unavailable_message' => 'The AI assistant is temporarily unavailable. Please try again later.',

];