<?php

namespace App\Services\AI;

use App\Services\OllamaClient;
use App\Services\SystemKnowledge;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one place that decides how a question is answered:
 *
 *   question -> deterministic rules (exact, from the database)
 *            -> otherwise the AI, reading only the data this user may see
 *            -> checked before the user sees it
 *
 * The AI never writes SQL and never touches the database. The app retrieves
 * the data first and the AI only reads it.
 */
class HRISAssistantService
{
    public function __construct(
        private RuleAnswers $rules,
        private SystemKnowledge $knowledge,
        private OllamaClient $ollama,
        private ConversationMemory $memory,
        private AnswerGuard $guard,
    ) {
    }

    public function ask(AssistantContext $ctx, string $question): array
    {
        $question = trim($question);
        $started = microtime(true);

        try {
            [$response, $path] = $this->respond($ctx, $question);
        } catch (Throwable $e) {
            // The details go to the log only. The user gets a safe message.
            Log::error('Assistant failed: ' . $e->getMessage(), ['exception' => $e]);

            $response = $this->failure($ctx);
            $path = 'error';
        }

        // A short audit trail. The question itself is not logged: it can hold personal data.
        Log::info('Assistant request', [
            'role' => $ctx->role,
            'user' => $ctx->userId,
            'path' => $path,
            'chars' => mb_strlen($question),
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Pipeline
    |--------------------------------------------------------------------------
    */

    // Returns [response, path] where path says how the answer was made.
    private function respond(AssistantContext $ctx, string $question): array
    {
        $q = $this->guard->normalize($question);
        $user = ['name' => $ctx->name, 'line' => $ctx->userLine()];

        // 1. Exact and instant: every number comes straight from the database.
        $answer = $this->rules->respond($ctx->role, $q, $user);

        if ($answer !== null) {
            $this->memory->remember($ctx, $question, '(showed ' . $answer['title'] . ')');

            return [$answer, 'rules'];
        }

        // 2. Anything else: the AI reads the data this user may see.
        return $this->viaAi($ctx, $question);
    }

    private function viaAi(AssistantContext $ctx, string $question): array
    {
        if (!$this->ollama->enabled()) {
            return [$this->rules->help($ctx->role, "Sorry, I didn't understand that"), 'ai-off'];
        }

        // "php artisan serve" stops a request after 30 seconds by default.
        // The first question to the model can take longer than that.
        @set_time_limit((int) config('ollama.timeout') + 15);

        $history = $this->memory->history($ctx);
        $knowledge = $this->knowledge->forRole($ctx->role, $ctx->name);

        $result = $this->ollama->answer(
            $question,
            $ctx->role,
            $ctx->userLine(),
            $knowledge['text'],
            $history
        );

        if ($result === null) {
            return [$this->unavailable($ctx), 'ai-unavailable:' . $this->ollama->failure()];
        }

        $reply = $this->guard->plain($result['answer']);

        if ($reply === '') {
            return [$this->rules->help($ctx->role, "Sorry, I didn't understand that"), 'ai-empty'];
        }

        // The exact lines the model says it used, taken from the database.
        $sources = [];

        foreach ($result['used'] as $id) {
            if (isset($knowledge['rows'][$id]) && count($sources) < 4) {
                $sources[] = $this->row($knowledge['rows'][$id], 'low', null, 'Source');
            }
        }

        // An answer about the system must not contain a number that is not in the data.
        // A "chat" answer to a question that sounds like HRIS gets the same check.
        $mustBeGrounded = $result['kind'] === 'data'
            || ($result['kind'] === 'chat' && $this->guard->looksLikeHris($this->guard->normalize($question)));

        if ($mustBeGrounded && !$this->guard->isGrounded($reply, $question, $knowledge['text'])) {
            $fallback = $this->rules->help($ctx->role, "I'm not sure about that");

            array_unshift($fallback['items'], ...$sources);
            array_unshift(
                $fallback['items'],
                $this->row('I could not confirm that from the system data, so I will not guess.', 'medium', null, 'Notice')
            );

            return [$fallback, 'ai-ungrounded'];
        }

        $this->memory->remember($ctx, $question, $reply);

        $response = match ($result['kind']) {
            'data' => $this->answer('Answer from system data', array_merge(
                [$this->row($reply, 'medium')],
                $sources
            )),
            'unknown' => $this->answer('Not found in the system', [$this->row($reply, 'medium')]),
            default => $this->answer('Assistant', [$this->row($reply, 'low')]),
        };

        return [$response, 'ai-' . $result['kind']];
    }

    /*
    |--------------------------------------------------------------------------
    | Safe fallbacks
    |--------------------------------------------------------------------------
    */

    // The AI is offline, too slow, or gave nothing usable. No technical details.
    private function unavailable(AssistantContext $ctx): array
    {
        $help = $this->rules->help($ctx->role, '', false);

        return $this->answer('Assistant unavailable', array_merge(
            [$this->row($this->ollama->friendlyMessage(), 'medium', null, 'Notice')],
            $help['items']
        ));
    }

    // Something unexpected failed. No technical details either.
    private function failure(AssistantContext $ctx): array
    {
        $help = $this->rules->help($ctx->role, '', false);

        return $this->answer('Sorry', array_merge(
            [$this->row('Something went wrong on my side, so I could not answer that. Please try again.', 'medium', null, 'Notice')],
            $help['items']
        ));
    }

    private function row(string $text, string $level = 'low', ?string $url = null, ?string $tag = null): array
    {
        return compact('text', 'level', 'url', 'tag');
    }

    private function answer(string $title, array $items): array
    {
        return [
            'title' => $title,
            'items' => array_values($items),
        ];
    }
}