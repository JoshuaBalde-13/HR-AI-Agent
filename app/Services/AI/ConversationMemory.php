<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the assistant remembers for one hour: the last question and the last
 * person talked about. It lets "last week?" and "siya" make sense.
 */
class ConversationMemory
{
    public const VERSION = 5;

    public function lastQuestion(AssistantContext $ctx): ?string
    {
        return Cache::get($this->key($ctx, 'question'));
    }

    public function lastPerson(AssistantContext $ctx): ?string
    {
        return Cache::get($this->key($ctx, 'person'));
    }

    public function remember(AssistantContext $ctx, string $question, ?string $person = null): void
    {
        Cache::put($this->key($ctx, 'question'), Str::limit($question, 200, ''), now()->addHour());

        // A person stays remembered until someone else is talked about.
        if ($person) {
            Cache::put($this->key($ctx, 'person'), $person, now()->addHour());
        }
    }

    private function key(AssistantContext $ctx, string $what): string
    {
        return "assistant:{$ctx->sessionKey}:{$ctx->role}:{$what}";
    }
}