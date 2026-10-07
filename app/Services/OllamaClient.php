<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to the local Ollama server through its OpenAI-compatible API.
 * No OpenAI account or billing is involved: the API key is only a placeholder.
 */
class OllamaClient
{
    // Why the last call failed, or null when it worked:
    // disabled | connection | timeout | model | http | empty | invalid
    private ?string $failure = null;

    public function enabled(): bool
    {
        return (bool) config('ollama.enabled');
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    // A message that is safe to show to users. It never contains technical details.
    public function friendlyMessage(): string
    {
        return match ($this->failure) {
            'empty', 'invalid' => 'The AI assistant could not produce an answer. Please try again.',
            default => (string) config('ollama.unavailable_message'),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    */

    /**
     * Ids of the models installed in Ollama, or null when the server cannot be reached.
     */
    public function models(): ?array
    {
        $this->failure = null;

        try {
            $response = $this->http()->timeout(5)->get('/models');
        } catch (Throwable $e) {
            $this->fail($e);

            return null;
        }

        if (!$response->successful()) {
            $this->failure = 'http';
            Log::warning('Ollama returned HTTP ' . $response->status() . ' for the model list.');

            return null;
        }

        return collect($response->json('data', []))
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * One chat completion. Returns the text of the answer, or null on failure
     * (see failure()). With $schema the model must answer in that JSON shape.
     */
    public function chat(
        array $messages,
        ?array $schema = null,
        ?int $maxTokens = null,
        float $temperature = 0.1
    ): ?string {
        $this->failure = null;

        if (!$this->enabled()) {
            $this->failure = 'disabled';

            return null;
        }

        $payload = [
            'model' => config('ollama.model'),
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens ?? (int) config('ollama.max_tokens'),
            'stream' => false,
        ];

        if ($schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'hris_answer',
                    'strict' => true,
                    'schema' => $schema,
                ],
            ];
        }

        try {
            $response = $this->http()->post('/chat/completions', $payload);
        } catch (Throwable $e) {
            $this->fail($e);

            return null;
        }

        // Ollama answers 404 when the model has not been pulled.
        if ($response->status() === 404) {
            $this->failure = 'model';
            Log::warning('Ollama model is not available: ' . (string) config('ollama.model'));

            return null;
        }

        if (!$response->successful()) {
            $this->failure = 'http';
            Log::warning('Ollama returned HTTP ' . $response->status());

            return null;
        }

        $content = data_get($response->json(), 'choices.0.message.content');

        if (!is_string($content) || trim($content) === '') {
            $this->failure = 'empty';
            Log::warning('Ollama returned an empty answer.');

            return null;
        }

        return $content;
    }

    /*
    |--------------------------------------------------------------------------
    | Answer a question from the system data
    |--------------------------------------------------------------------------
    |
    | The model only reads the DATA it is given. It must name the ids of the
    | lines it used ("used") before it writes the answer, and the app checks
    | the numbers afterwards. Returns null when the AI is off or did not answer.
    |
    */

    public function answer(
        string $question,
        string $role,
        string $userLine,
        string $data,
        array $history
    ): ?array {
        $system = implode("\n", [
            'You are the HRIS Assistant of Frontier Security Services Inc., a security agency. The user is ' . $userLine . '.',
            "Answer the user's message using ONLY the DATA below. It is the live system data this user is allowed to see. The user may write in English, Filipino/Tagalog, or a mix: reply in the same language.",
            '',
            'Reply with ONE JSON object with the keys "kind", "used" and "answer".',
            '- kind "data": the answer is in DATA. Copy numbers, names, dates and statuses exactly as written. In "used" put the ids of the DATA lines you used (like "E1" or "S2").',
            '- kind "unknown": the message is about this HRIS but DATA does not contain it, or it asks about other people when the user may only see their own information. Say briefly what you cannot see, then suggest one thing you can answer. "used" is empty.',
            '- kind "chat": the message is not about the HRIS (greetings, math, general knowledge, advice). Answer normally. "used" is empty. Never mention HRIS numbers or names in a chat answer.',
            'Rules: never invent numbers, names or dates. Do not calculate: use the totals already written in DATA. Keep the answer to 1-3 short sentences. Write plain text only: no emojis and no markdown.',
            '',
            'DATA',
            $data,
        ]);

        $content = $this->chat(
            [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $this->userMessage($question, $history)],
            ],
            [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['data', 'unknown', 'chat']],
                    'used' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'answer' => ['type' => 'string'],
                ],
                'required' => ['kind', 'used', 'answer'],
                'additionalProperties' => false,
            ]
        );

        if ($content === null) {
            return null;
        }

        $fields = json_decode($content, true);

        if (!is_array($fields)) {
            // The model ran out of room mid-sentence: keep what it wrote.
            $fields = $this->salvage($content);

            if ($fields === null) {
                $this->failure = 'invalid';
                Log::warning('Ollama gave an unreadable answer.');

                return null;
            }
        }

        $kind = $fields['kind'] ?? null;

        if (!is_string($kind) || !in_array($kind, ['data', 'unknown', 'chat'], true)) {
            $this->failure = 'invalid';
            Log::warning('Ollama gave an unknown answer type.');

            return null;
        }

        $used = collect(is_array($fields['used'] ?? null) ? $fields['used'] : [])
            ->filter(fn ($id) => is_string($id))
            ->map(fn ($id) => strtoupper(trim($id, " []\"'.,")))
            ->filter()
            ->unique()
            ->take(6)
            ->values()
            ->all();

        $answer = trim(mb_substr((string) ($fields['answer'] ?? ''), 0, 600));

        // Log the shape, not the content: answers can contain personal data.
        Log::info('Ollama answered', [
            'kind' => $kind,
            'used' => $used,
            'chars' => mb_strlen($answer),
        ]);

        return ['kind' => $kind, 'used' => $used, 'answer' => $answer];
    }

    /*
    |--------------------------------------------------------------------------
    | Internals
    |--------------------------------------------------------------------------
    */

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('ollama.base_url'), '/'))
            ->withToken((string) config('ollama.api_key'))
            ->acceptJson()
            ->asJson()
            ->connectTimeout((int) config('ollama.connect_timeout'))
            ->timeout((int) config('ollama.timeout'));
    }

    // Sort an exception into a short reason. The raw message only goes to the log.
    private function fail(Throwable $e): void
    {
        $message = strtolower($e->getMessage());

        $this->failure = str_contains($message, 'timed out') || str_contains($message, 'curl error 28')
            ? 'timeout'
            : 'connection';

        Log::warning("Ollama {$this->failure}: " . $e->getMessage());
    }

    private function userMessage(string $question, array $history): string
    {
        $lines = [];

        if ($history) {
            $lines[] = 'Recent conversation:';

            foreach ($history as $turn) {
                $who = $turn['role'] === 'user' ? 'User' : 'Assistant';
                $lines[] = "{$who}: " . $turn['text'];
            }

            $lines[] = '';
        }

        $lines[] = 'Message: ' . mb_substr($question, 0, 300);

        return implode("\n", $lines);
    }

    private function salvage(string $content): ?array
    {
        if (!preg_match('/"answer"\s*:\s*"((?:[^"\\\\]|\\\\.)*)/u', $content, $answer)) {
            return null;
        }

        $text = json_decode('"' . $answer[1] . '"');
        $text = is_string($text) ? $text : stripcslashes($answer[1]);

        $kind = preg_match('/"kind"\s*:\s*"(data|unknown|chat)"/', $content, $match)
            ? $match[1]
            : 'data';

        return ['kind' => $kind, 'used' => [], 'answer' => trim($text)];
    }
}