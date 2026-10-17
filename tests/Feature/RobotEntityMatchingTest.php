<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RobotEntityMatchingTest extends TestCase
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

    private function askAsHr(string $question): string
    {
        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => $question,
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        return mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );
    }

    public function test_full_applicant_name_takes_precedence_over_overlapping_short_name(): void
    {
        $kym = Applicant::create([
            'name' => 'Kym Mendroga',
            'email' => 'kym.entity-test@example.com',
            'password' => Hash::make('test-password'),
        ]);

        $shortName = Applicant::create([
            'name' => 'mendroga',
            'email' => 'mendroga.entity-test@example.com',
            'password' => Hash::make('test-password'),
        ]);

        Application::create([
            'applicant_id' => $kym->id,
            'job_title' => 'Security Guard',
            'status' => 'rejected',
            'step' => 3,
            'submitted_at' => now()->subDays(3),
            'reviewed_at' => now()->subDay(),
        ]);

        Application::create([
            'applicant_id' => $shortName->id,
            'job_title' => 'Security Guard',
            'status' => 'approved',
            'step' => 3,
            'submitted_at' => now()->subDays(2),
            'reviewed_at' => now()->subDay(),
        ]);

        $answer = $this->askAsHr(
            "What is Kym Mendroga's application status?"
        );

        $this->assertStringContainsString('kym mendroga', $answer);
        $this->assertStringContainsString('rejected', $answer);

        $this->assertStringNotContainsString('which person', $answer);
        $this->assertStringNotContainsString(
            'mendroga, security guard (approved)',
            $answer
        );
    }

    public function test_unknown_employee_record_question_does_not_return_general_employee_list(): void
    {
        Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        $answer = $this->askAsHr(
            "Show me Alex's employee record."
        );

        $this->assertStringContainsString('alex', $answer);

        $this->assertTrue(
            str_contains($answer, 'not found')
                || str_contains($answer, "can't find"),
            "Expected a clear not-found response for Alex. Answer: {$answer}"
        );

        $this->assertStringNotContainsString(
            'person(s) on file',
            $answer
        );
    }
    public function test_unknown_full_employee_name_does_not_match_existing_employee_by_first_name(): void
{
    Employee::create([
        'name' => 'Joshua Balde',
        'position' => 'Security Guard',
        'employment_status' => 'Active',
    ]);

    $answer = $this->askAsHr(
        "What is Joshua Santos's position?"
    );

    $this->assertStringContainsString('joshua santos', $answer);

    $this->assertTrue(
        str_contains($answer, 'not found')
            || str_contains($answer, "can't find"),
        "Expected Joshua Santos to be reported as not found. Answer: {$answer}"
    );

    $this->assertStringNotContainsString('joshua balde', $answer);
}
}