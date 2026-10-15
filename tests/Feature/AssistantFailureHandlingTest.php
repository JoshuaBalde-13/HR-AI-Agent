<?php

namespace Tests\Feature;

use App\Services\AI\AssistantContext;
use App\Services\AI\HRISAssistantService;
use App\Services\AI\RuleAnswers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AssistantFailureHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_exception_returns_a_safe_error_message(): void
    {
        $safeMessage =
            'Something went wrong on my side, so I could not answer that. Please try again.';

        $rules = \Mockery::mock(RuleAnswers::class);

        $rules->shouldReceive('respond')
            ->once()
            ->andThrow(new RuntimeException('SENSITIVE_INTERNAL_TEST_DETAIL'));

        $rules->shouldReceive('help')
            ->once()
            ->with('HR', $safeMessage, false)
            ->andReturn([
                'title' => 'Help',
                'lead' => true,
                'items' => [[
                    'text' => $safeMessage,
                    'level' => 'medium',
                    'url' => null,
                    'tag' => null,
                ]],
            ]);

        $this->app->instance(RuleAnswers::class, $rules);

        $context = new AssistantContext(
            'HR',
            null,
            null,
            'dashboard',
            'failure-test-session'
        );

        $result = app(HRISAssistantService::class)
            ->askWithPath($context, 'Give me an unfamiliar report.');

        $responseText = mb_strtolower(
            json_encode($result['response'], JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame('error', $result['path']);
        $this->assertStringContainsString(
            mb_strtolower($safeMessage),
            $responseText
        );
        $this->assertStringNotContainsString(
            'sensitive_internal_test_detail',
            $responseText
        );
    }

    public function test_unsupported_question_uses_safe_fallback_when_ai_is_disabled(): void
    {
        config(['ollama.enabled' => false]);

        $context = new AssistantContext(
            'HR',
            null,
            null,
            'dashboard',
            'unsupported-question-test-session'
        );

        $result = app(HRISAssistantService::class)
            ->askWithPath($context, 'What is the weather on Jupiter?');

        $responseText = mb_strtolower(
            json_encode($result['response'], JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame('ai-off', $result['path']);
        $this->assertStringContainsString(
            "i didn't understand that",
            $responseText
        );
        $this->assertStringNotContainsString(
            'the weather on jupiter is',
            $responseText
        );
    }
}