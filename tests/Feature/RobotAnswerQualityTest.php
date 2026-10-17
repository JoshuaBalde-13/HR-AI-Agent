<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RobotAnswerQualityTest extends TestCase
{
    use RefreshDatabase;

    private User $hr;

    protected function setUp(): void
{
    parent::setUp();

    config(['ollama.enabled' => false]);

    $this->hr = User::factory()->create([
        'role' => User::ROLE_HR,
    ]);

    $this->withCredentials();
    $this->actingAs($this->hr, 'web');
}

    private function createJoshua(): Employee
    {
        return Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
            'date_hired' => '2020-03-15',
        ]);
    }

   private function askAsHr(string $question): string
{
    $response = $this->postJson(route('robot.ask'), [
        'question' => $question,
        'role' => 'HR',
        'employee_name' => null,
    ]);

    $response->assertOk();

    // Preserve the session cookie for the next question.
    $sessionCookieName = config('session.cookie');

    $sessionCookie = $response
        ->getCookie($sessionCookieName)?->getValue();

    if ($sessionCookie !== null) {
        $this->withCookie($sessionCookieName, $sessionCookie);
    }

    return mb_strtolower(
        json_encode($response->json(), JSON_UNESCAPED_UNICODE)
    );
}

    public function test_position_question_returns_only_the_requested_fact(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr(
            "What is Joshua Balde's position?"
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('security guard', $answer);

        $this->assertStringNotContainsString('date hired', $answer);
        $this->assertStringNotContainsString('employment status', $answer);
        $this->assertStringNotContainsString('worked hours', $answer);
        $this->assertStringNotContainsString('document', $answer);
        $this->assertStringNotContainsString('application', $answer);
    }

    public function test_hiring_date_question_returns_only_the_hiring_date(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr(
            'When was Joshua Balde hired?'
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('mar 15, 2020', $answer);

        $this->assertStringNotContainsString('security guard', $answer);
        $this->assertStringNotContainsString('employment status', $answer);
        $this->assertStringNotContainsString('worked hours', $answer);
        $this->assertStringNotContainsString('document', $answer);
        $this->assertStringNotContainsString('application', $answer);
    }

    public function test_active_status_question_returns_only_employment_status(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr(
            'Is Joshua Balde active?'
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('active', $answer);

        $this->assertStringNotContainsString('date hired', $answer);
        $this->assertStringNotContainsString('security guard', $answer);
        $this->assertStringNotContainsString('worked hours', $answer);
        $this->assertStringNotContainsString('document', $answer);
        $this->assertStringNotContainsString('application', $answer);
    }

    public function test_employee_existence_question_does_not_return_general_employee_list(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr(
            'May employee ba tayong Dagul?'
        );

        $this->assertStringContainsString('dagul', $answer);

        $this->assertTrue(
            str_contains($answer, 'not found')
                || str_contains($answer, 'no employee'),
            "Expected a clear employee not-found answer. Actual: {$answer}"
        );

        $this->assertStringNotContainsString('person(s) on file', $answer);
        $this->assertStringNotContainsString('joshua balde', $answer);
    }

    public function test_temporal_follow_up_keeps_the_previous_applications_topic(): void
    {
        $todayApplicant = Applicant::create([
            'name' => 'Ana Dela Cruz',
            'email' => 'ana.quality-test@example.com',
            'password' => Hash::make('test-password'),
        ]);

        $yesterdayApplicant = Applicant::create([
            'name' => 'Mario Reyes',
            'email' => 'mario.quality-test@example.com',
            'password' => Hash::make('test-password'),
        ]);

        Application::create([
            'applicant_id' => $todayApplicant->id,
            'job_title' => 'Security Guard',
            'status' => 'submitted',
            'step' => 3,
            'submitted_at' => today()->setTime(10, 0),
        ]);

        Application::create([
            'applicant_id' => $yesterdayApplicant->id,
            'job_title' => 'Security Guard',
            'status' => 'submitted',
            'step' => 3,
            'submitted_at' => today()->subDay()->setTime(10, 0),
        ]);

        $firstAnswer = $this->askAsHr(
            'Ilan applicant natin ngayon?'
        );

        $this->assertStringContainsString(
            'application(s)',
            $firstAnswer
        );

        $followUpAnswer = $this->askAsHr('Ilan kahapon?');

        $this->assertStringContainsString(
            'application(s)',
            $followUpAnswer
        );

        $this->assertStringContainsString(
            'yesterday',
            $followUpAnswer
        );

        $this->assertStringContainsString(
            '1 application(s)',
            $followUpAnswer
        );

        $this->assertStringNotContainsString(
            'on duty',
            $followUpAnswer
        );
    }
}
