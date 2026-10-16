<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotEmployeeProfileTest extends TestCase
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

    public function test_hr_can_ask_when_an_employee_was_hired(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr('When was Joshua hired?');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('date hired', $answer);
        $this->assertStringContainsString('mar 15, 2020', $answer);
    }

    public function test_hr_can_ask_an_employees_position(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr('What position does Joshua have?');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('position', $answer);
        $this->assertStringContainsString('security guard', $answer);
    }

    public function test_hr_can_ask_whether_an_employee_is_active(): void
    {
        $this->createJoshua();

        $answer = $this->askAsHr('Is Joshua active?');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('employment status', $answer);
        $this->assertStringContainsString('active', $answer);
    }

    public function test_employee_profile_finds_attendance_and_documents_by_employee_id_when_legacy_names_differ(): void
    {
        $employee = $this->createJoshua();

        $clockIn = today()->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'time_in' => $clockIn,
            'time_out' => $clockIn->copy()->addHours(8),
        ]);

        EmployeeDocument::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'document_type' => 'Government ID',
            'expiry_date' => today()->addDays(90),
        ]);

        $answer = $this->askAsHr('Tell me about Joshua');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('worked hours', $answer);
        $this->assertStringContainsString('8 h', $answer);
        $this->assertStringContainsString('government id', $answer);
        $this->assertStringContainsString('(valid)', $answer);
    }


    public function test_hr_is_asked_to_clarify_ambiguous_employee_profile_name(): void
    {
        $this->createJoshua();

        Employee::create([
            'name' => 'Joshua Santos',
            'position' => 'Operations Supervisor',
            'employment_status' => 'Active',
            'date_hired' => '2021-04-10',
        ]);

        $answer = $this->askAsHr('What position does Joshua have?');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('joshua santos', $answer);

        $this->assertTrue(
            str_contains($answer, 'which employee')
                || str_contains($answer, 'more than one match')
                || str_contains($answer, 'full name')
                || str_contains($answer, 'which one'),
            "Expected the assistant to ask HR to clarify which Joshua was meant. Answer: {$answer}"
        );
    }
}
