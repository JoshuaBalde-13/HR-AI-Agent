<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AI\AssistantContext;
use App\Services\AI\HRISAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotAiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = User::factory()->create([
            'role' => User::ROLE_HR,
        ]);
    }

    public function test_unmatched_controller_rules_use_modular_assistant_with_trusted_role(): void
    {
        $question = 'Can you explain this workflow in simple terms?';

        $assistant = \Mockery::mock(HRISAssistantService::class);

        $assistant->shouldReceive('askWithPath')
            ->once()
            ->with(
                \Mockery::on(
                    fn ($context) => $context instanceof AssistantContext
                        && $context->role === 'HR'
                        && $context->name === null
                ),
                $question
            )
            ->andReturn([
                'response' => [
                    'title' => 'Modular integration test',
                    'items' => [[
                        'text' => 'Answered by the modular assistant.',
                        'level' => 'low',
                        'url' => null,
                        'tag' => null,
                    ]],
                    'v' => 6,
                ],
                'path' => 'ai-person',
            ]);

        $this->app->instance(HRISAssistantService::class, $assistant);

        // A forged role must not override the authenticated HR staff role.
        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => $question,
                'role' => 'Employee',
                'employee_name' => 'Fake Name',
            ]);

        $response->assertOk();
        $response->assertJsonPath('title', 'Modular integration test');
        $response->assertJsonPath(
            'items.0.text',
            'Answered by the modular assistant.'
        );
    }

    public function test_unknown_modular_intent_preserves_legacy_fallback(): void
    {
        config(['ollama.enabled' => false]);

        $question = 'Can you explain the meaning of this phrase?';

        $assistant = \Mockery::mock(HRISAssistantService::class);

        $assistant->shouldReceive('askWithPath')
            ->once()
            ->andReturn([
                'response' => [
                    'title' => 'Modular sentinel',
                    'items' => [[
                        'text' => 'This modular sentinel must not be returned.',
                        'level' => 'low',
                        'url' => null,
                        'tag' => null,
                    ]],
                    'v' => 6,
                ],
                'path' => 'ai-unknown',
            ]);

        $this->app->instance(HRISAssistantService::class, $assistant);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => $question,
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $body = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString(
            "sorry, i didn't understand that",
            $body
        );
        $this->assertStringNotContainsString(
            'modular sentinel must not be returned',
            $body
        );
    }
}
