<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
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

    public function test_robot_endpoint_preserves_clarified_employee_across_requests(): void
    {
        $this->withCredentials();

        config(['ollama.enabled' => false]);

        Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        Employee::create([
            'name' => 'Joshua Santos',
            'position' => 'Security Officer',
            'employment_status' => 'Active',
        ]);

        $baldeClockIn = now()->subDays(3)->setTime(8, 0);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $baldeClockIn,
            'time_out' => $baldeClockIn->copy()->addHours(8),
        ]);

        $santosClockIn = now()->subDay()->setTime(10, 0);

        AttendanceLog::create([
            'employee_name' => 'Joshua Santos',
            'time_in' => $santosClockIn,
            'time_out' => $santosClockIn->copy()->addHours(8),
        ]);

        $this->actingAs($this->hr, 'web');

        $clarification = $this->postJson(route('robot.ask'), [
            'question' => 'What position does Joshua have?',
            'role' => 'HR',
            'employee_name' => null,
        ]);

        $clarification->assertOk();

        $sessionCookieName = config('session.cookie');
        $sessionCookie = $clarification->getCookie($sessionCookieName)?->getValue();

        $this->assertNotNull(
            $sessionCookie,
            'The first response should include the session cookie.'
        );

        $this->withCookie($sessionCookieName, $sessionCookie);

        $clarificationText = mb_strtolower(
            json_encode($clarification->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $clarificationText);
        $this->assertStringContainsString('joshua santos', $clarificationText);

        $previousSessionCookie = $sessionCookie;

        $selection = $this->postJson(route('robot.ask'), [
            'question' => 'Joshua Santos',
            'role' => 'HR',
            'employee_name' => null,
        ]);

        $selection->assertOk();

        $sessionCookie = $selection->getCookie($sessionCookieName)?->getValue();

        $this->assertNotNull(
            $sessionCookie,
            'The selection response should include the session cookie.'
        );

        $this->assertSame(
            $previousSessionCookie,
            $sessionCookie,
            'The session ID should remain stable across clarification and selection.'
        );

        $this->withCookie($sessionCookieName, $sessionCookie);

        $selectionText = mb_strtolower(
            json_encode($selection->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua santos', $selectionText);
        $this->assertStringContainsString('security officer', $selectionText);
        $this->assertStringNotContainsString('joshua balde', $selectionText);

        $attendance = $this->postJson(route('robot.ask'), [
            'question' => 'When did he last clock in?',
            'role' => 'HR',
            'employee_name' => null,
        ]);

        $attendance->assertOk();

        $attendanceText = mb_strtolower(
            json_encode($attendance->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua santos', $attendanceText);
        $this->assertStringContainsString(
            mb_strtolower($santosClockIn->format('M j')),
            $attendanceText
        );
        $this->assertStringNotContainsString('joshua balde', $attendanceText);
    }
}
