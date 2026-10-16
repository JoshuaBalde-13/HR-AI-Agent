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

    public function test_robot_endpoint_clears_selected_employee_after_unrelated_greeting(): void
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

    $santosClockIn = now()->subDay()->setTime(10, 0);

    AttendanceLog::create([
        'employee_name' => 'Joshua Santos',
        'time_in' => $santosClockIn,
        'time_out' => $santosClockIn->copy()->addHours(8),
    ]);

    $this->actingAs($this->hr, 'web');

    $sessionCookieName = config('session.cookie');

    // 1. Ask about an ambiguous first name.
    $clarification = $this->postJson(route('robot.ask'), [
        'question' => 'What position does Joshua have?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $clarification->assertOk();

    $sessionCookie = $clarification
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($sessionCookie);

    $this->withCookie($sessionCookieName, $sessionCookie);

    // 2. Select Joshua Santos.
    $selection = $this->postJson(route('robot.ask'), [
        'question' => 'Joshua Santos',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $selection->assertOk();

    $selectionCookie = $selection
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($selectionCookie);
    $this->assertSame($sessionCookie, $selectionCookie);

    $this->withCookie($sessionCookieName, $selectionCookie);

    $selectionText = mb_strtolower(
        json_encode($selection->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua santos', $selectionText);
    $this->assertStringContainsString('security officer', $selectionText);

    // 3. Ask an unrelated question. This should clear the selected employee.
    $thanks = $this->postJson(route('robot.ask'), [
        'question' => 'Thank you',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $thanks->assertOk();

    $thanksCookie = $thanks
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($thanksCookie);
    $this->assertSame($sessionCookie, $thanksCookie);

    $this->withCookie($sessionCookieName, $thanksCookie);

    // 4. A pronoun must not reuse Joshua Santos after the subject changed.
    $attendance = $this->postJson(route('robot.ask'), [
        'question' => 'When did he last clock in?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $attendance->assertOk();

    $attendanceText = mb_strtolower(
        json_encode($attendance->json(), JSON_UNESCAPED_UNICODE)
    );

    // The robot should request a name, not reveal Santos's attendance.
    $this->assertStringContainsString(
        'please include the employee name',
        $attendanceText
    );

    $this->assertStringNotContainsString(
        'joshua santos',
        $attendanceText
    );

    $this->assertStringNotContainsString(
        mb_strtolower($santosClockIn->format('M j, Y g:i A')),
        $attendanceText
    );
}
public function test_robot_endpoint_keeps_employee_context_isolated_between_sessions(): void
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

    $santosClockIn = now()->subDay()->setTime(10, 0);

    AttendanceLog::create([
        'employee_name' => 'Joshua Santos',
        'time_in' => $santosClockIn,
        'time_out' => $santosClockIn->copy()->addHours(8),
    ]);

    $this->actingAs($this->hr, 'web');

    $sessionCookieName = config('session.cookie');

    // Session A: ask about an ambiguous first name.
    $clarification = $this->postJson(route('robot.ask'), [
        'question' => 'What position does Joshua have?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $clarification->assertOk();

    $sessionCookieA = $clarification
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($sessionCookieA);

    $this->withCookie($sessionCookieName, $sessionCookieA);

    // Session A: select Joshua Santos.
    $selection = $this->postJson(route('robot.ask'), [
        'question' => 'Joshua Santos',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $selection->assertOk();

    $selectionText = mb_strtolower(
        json_encode($selection->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua santos', $selectionText);
    $this->assertStringContainsString('security officer', $selectionText);

    $selectionCookie = $selection
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertSame($sessionCookieA, $selectionCookie);

    // Session B: remove Session A's cookie to start a separate session.
    $this->withCookie($sessionCookieName, 'invalid-session-cookie');

    $sessionBResponse = $this->postJson(route('robot.ask'), [
        'question' => 'When did he last clock in?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $sessionBResponse->assertOk();

    $sessionCookieB = $sessionBResponse
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($sessionCookieB);
    $this->assertNotSame(
        $sessionCookieA,
        $sessionCookieB,
        'Session B must have a different session cookie from Session A.'
    );

    $sessionBText = mb_strtolower(
        json_encode($sessionBResponse->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString(
        'please include the employee name',
        $sessionBText
    );

    $this->assertStringNotContainsString('joshua santos', $sessionBText);

    $this->assertStringNotContainsString(
        mb_strtolower($santosClockIn->format('M j, Y g:i A')),
        $sessionBText
    );

    // Return to Session A: its selected employee context should still work.
    $this->withCookie($sessionCookieName, $sessionCookieA);

    $sessionAFollowUp = $this->postJson(route('robot.ask'), [
        'question' => 'When did he last clock in?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $sessionAFollowUp->assertOk();

    $sessionAText = mb_strtolower(
        json_encode($sessionAFollowUp->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua santos', $sessionAText);

    $this->assertStringContainsString(
        mb_strtolower($santosClockIn->format('M j')),
        $sessionAText
    );
}
public function test_robot_endpoint_switches_employee_context_when_another_employee_is_named(): void
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
    $santosClockIn = now()->subDay()->setTime(10, 0);

    AttendanceLog::create([
        'employee_name' => 'Joshua Balde',
        'time_in' => $baldeClockIn,
        'time_out' => $baldeClockIn->copy()->addHours(8),
    ]);

    AttendanceLog::create([
        'employee_name' => 'Joshua Santos',
        'time_in' => $santosClockIn,
        'time_out' => $santosClockIn->copy()->addHours(8),
    ]);

    $this->actingAs($this->hr, 'web');

    $sessionCookieName = config('session.cookie');

    // 1. Select Joshua Santos.
    $selection = $this->postJson(route('robot.ask'), [
        'question' => 'Joshua Santos',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $selection->assertOk();

    $sessionCookie = $selection
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($sessionCookie);

    $this->withCookie($sessionCookieName, $sessionCookie);

    $selectionText = mb_strtolower(
        json_encode($selection->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua santos', $selectionText);
    $this->assertStringContainsString('security officer', $selectionText);

    // 2. Explicitly switch to Joshua Balde.
    $switch = $this->postJson(route('robot.ask'), [
        'question' => 'What position does Joshua Balde have?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $switch->assertOk();

    $switchCookie = $switch
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($switchCookie);
    $this->assertSame(
        $sessionCookie,
        $switchCookie,
        'The session should remain stable while switching employees.'
    );

    $this->withCookie($sessionCookieName, $switchCookie);

    $switchText = mb_strtolower(
        json_encode($switch->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua balde', $switchText);
    $this->assertStringContainsString('security guard', $switchText);
    $this->assertStringNotContainsString('joshua santos', $switchText);

    // 3. The pronoun follow-up should now refer to Joshua Balde.
    $attendance = $this->postJson(route('robot.ask'), [
        'question' => 'When did he last clock in?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $attendance->assertOk();

    $attendanceText = mb_strtolower(
        json_encode($attendance->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua balde', $attendanceText);

    $this->assertStringContainsString(
        mb_strtolower($baldeClockIn->format('M j')),
        $attendanceText
    );

    $this->assertStringNotContainsString('joshua santos', $attendanceText);

    $this->assertStringNotContainsString(
        mb_strtolower($santosClockIn->format('M j')),
        $attendanceText
    );
}

public function test_robot_endpoint_does_not_reuse_selected_employee_after_unknown_name(): void
{
    $this->withCredentials();

    config(['ollama.enabled' => false]);

    Employee::create([
        'name' => 'Joshua Santos',
        'position' => 'Security Officer',
        'employment_status' => 'Active',
    ]);

    $santosClockIn = now()->subDay()->setTime(10, 0);

    AttendanceLog::create([
        'employee_name' => 'Joshua Santos',
        'time_in' => $santosClockIn,
        'time_out' => $santosClockIn->copy()->addHours(8),
    ]);

    $this->actingAs($this->hr, 'web');

    $sessionCookieName = config('session.cookie');

    // 1. Select Joshua Santos.
    $selection = $this->postJson(route('robot.ask'), [
        'question' => 'Joshua Santos',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $selection->assertOk();

    $sessionCookie = $selection
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($sessionCookie);

    $this->withCookie($sessionCookieName, $sessionCookie);

    $selectionText = mb_strtolower(
        json_encode($selection->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString('joshua santos', $selectionText);
    $this->assertStringContainsString('security officer', $selectionText);

    // 2. Ask about an unknown employee.
    $unknown = $this->postJson(route('robot.ask'), [
        'question' => "What is Alex's position?",
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $unknown->assertOk();

    $unknownCookie = $unknown
        ->getCookie($sessionCookieName)?->getValue();

    $this->assertNotNull($unknownCookie);

    $this->assertSame(
        $sessionCookie,
        $unknownCookie,
        'The session should remain stable after an unknown-name question.'
    );

    $this->withCookie($sessionCookieName, $unknownCookie);

    $unknownText = mb_strtolower(
        json_encode($unknown->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringNotContainsString('joshua santos', $unknownText);

    // 3. Follow up with a pronoun. It must not reuse Joshua Santos.
    $attendance = $this->postJson(route('robot.ask'), [
        'question' => 'When did he last clock in?',
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $attendance->assertOk();

    $attendanceText = mb_strtolower(
        json_encode($attendance->json(), JSON_UNESCAPED_UNICODE)
    );

    $this->assertStringContainsString(
        'please include the employee name',
        $attendanceText
    );

    $this->assertStringNotContainsString(
        'joshua santos',
        $attendanceText
    );

    $this->assertStringNotContainsString(
        mb_strtolower($santosClockIn->format('M j, Y g:i A')),
        $attendanceText
    );
}

}
