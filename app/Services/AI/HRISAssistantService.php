<?php

namespace App\Services\AI;

use App\Services\OllamaClient;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that decides how a question is answered:
 *
 *   question -> rules (exact, instant, straight from the database)
 *            -> otherwise the AI works out WHAT is being asked (an intent),
 *               and the rules answer it
 *
 * The AI never writes an answer and never touches the database, so every
 * number and sentence the user sees comes from the application.
 */
class HRISAssistantService
{
    public const VERSION = 5;

    // Words that say what kind of answer is wanted. They are kept when the AI rewrites a question.
    private const MARKERS = ['sino', 'who', 'ilan', 'ilang', 'kailan', 'when', 'ba', 'may', 'meron', 'mayroon'];

    public function __construct(
        private RuleAnswers $rules,
        private IntentRouter $router,
        private OllamaClient $ollama,
        private ConversationMemory $memory,
        private AnswerGuard $guard,
    ) {
    }

    public function ask(AssistantContext $ctx, string $question): array
    {
        return $this->askWithPath($ctx, $question)['response'];
    }

    /**
     * Returns the response plus an internal path so the web controller
     * can decide whether to use its existing fallback.
     *
     * @return array{response: array, path: string}
     */
    public function askWithPath(AssistantContext $ctx, string $question): array
    {
        $question = trim($question);
        $started = microtime(true);

        try {
            [$response, $path] = $this->respond($ctx, $question);
        } catch (Throwable $e) {
            // Details stay in the log. The user receives a safe message.
            Log::error('Assistant failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            $response = $this->rules->help(
                $ctx->role,
                'Something went wrong on my side, so I could not answer that. Please try again.',
                false
            );
            $path = 'error';
        }

        // Internal fields never go to the browser.
        unset(
            $response['person'],
            $response['asked'],
            $response['clear_person']
        );

        $response['v'] = RuleAnswers::VERSION;

        // Do not log the question itself; it may contain personal data.
        Log::info('Assistant request', [
            'role' => $ctx->role,
            'user' => $ctx->userId,
            'path' => $path,
            'chars' => mb_strlen($question),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return [
            'response' => $response,
            'path' => $path,
        ];
    }

    // Returns [response, path] where path says how the answer was made.
    private function respond(AssistantContext $ctx, string $question): array
    {
        $q = $this->guard->normalize($question);

        $user = [
            'name' => $ctx->name,
            'line' => $ctx->userLine(),
            'person' => $this->memory->lastPerson($ctx),
            'previous' => $this->memory->lastQuestion($ctx),
        ];

        // 1. Exact and instant.
        $answer = $this->rules->respond($ctx->role, $q, $user);

        if ($answer !== null) {
            $this->remember($ctx, $answer, $question);

            return [$answer, 'rules'];
        }

        // 2. Not understood by the rules: ask the AI what is being asked.
        return $this->viaAi($ctx, $question, $user);
    }

    private function viaAi(AssistantContext $ctx, string $question, array $user): array
    {
        if (!$this->ollama->enabled()) {
            return [$this->rules->help($ctx->role, "I didn't understand that. Try asking it another way."), 'ai-off'];
        }

        // "php artisan serve" stops a request after 30 seconds by default.
        @set_time_limit((int) config('ollama.timeout') + 15);

        $routed = $this->router->route(
            $question,
            $ctx->role,
            $this->shortFollowUp($question) ? $user['previous'] : null,
            $user['person']
        );

        // The AI is offline, too slow, or gave nothing usable. No technical details.
        if ($routed === null) {
            return [
                $this->rules->help($ctx->role, $this->ollama->friendlyMessage(), false),
                'ai-unavailable:' . $this->ollama->failure(),
            ];
        }

        if ($routed['canonical'] !== null) {
            // The kind of question (who, how many, yes/no) is kept from the original words.
            $command = trim($routed['canonical'] . ' ' . $this->markers($question));

            $answer = $this->rules->respond($ctx->role, $this->guard->normalize($command), $user);

            if ($answer !== null) {
                $this->remember($ctx, $answer, $routed['canonical']);

                $answer['note'] = 'Understood as: ' . $routed['canonical'];

                return [$answer, 'ai-' . $routed['intent']];
            }
        }

        return [
            $this->rules->help($ctx->role, "I'm not sure what you want to know. Try asking it another way."),
            'ai-unknown',
        ];
    }

    // Only answers that are about a topic are remembered ("thank you" is not).
    private function remember(AssistantContext $ctx, array $answer, string $question): void
    {
        if (($answer['clear_person'] ?? false) === true) {
            $this->memory->forgetPerson($ctx);
        }

        if (($answer['title'] ?? '') === '') {
            return;
        }

        $this->memory->remember(
            $ctx,
            $answer['asked'] ?? $question,
            $answer['person'] ?? null
        );
    }

    private function shortFollowUp(string $question): bool
    {
        return count(preg_split('/\s+/u', trim($question), -1, PREG_SPLIT_NO_EMPTY)) <= 6;
    }

    private function markers(string $question): string
    {
        $q = $this->guard->normalize($question);
        $found = [];

        foreach (self::MARKERS as $word) {
            if (preg_match('/\b' . $word . '\b/u', $q)) {
                $found[] = $word;
            }
        }

        if (str_contains($q, 'how many')) {
            $found[] = 'how many';
        }

        return implode(' ', $found);
    }
}