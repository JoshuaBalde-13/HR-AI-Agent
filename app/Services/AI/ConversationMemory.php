<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The last 3 exchanges of a conversation, kept for one hour.
 * It lets the assistant understand follow-ups like "what about yesterday?".
 */
class ConversationMemory
{
    public function history(AssistantContext $ctx): array
    {
        return Cache::get($this->key($ctx), []);
    }

    public function remember(AssistantContext $ctx, string $question, string $reply): void
    {
        $key = $this->key($ctx);

        $history = Cache::get($key, []);
        $history[] = ['role' => 'user', 'text' => Str::limit($question, 200, '')];
        $history[] = ['role' => 'assistant', 'text' => Str::limit($reply, 200, '')];

        Cache::put($key, array_slice($history, -6), now()->addHour());
    }

    private function key(AssistantContext $ctx): string
    {
        return "assistant-chat:{$ctx->sessionKey}:{$ctx->role}";
    }
}