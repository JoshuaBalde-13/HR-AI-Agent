<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Services\AI\AssistantContext;
use App\Services\AI\HRISAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModularRuleRoutingTest extends TestCase
{
    use RefreshDatabase;

    private function askHr(string $question): string
    {
        $context = new AssistantContext(
            'HR',
            null,
            null,
            'dashboard',
            'modular-routing-' . uniqid()
        );

        $response = app(HRISAssistantService::class)->ask($context, $question);

        return mb_strtolower(
            json_encode($response, JSON_UNESCAPED_UNICODE)
        );
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

    public function test_since_when_question_returns_employee_hire_date(): void
    {
        $this->createJoshua();

        $answer = $this->askHr(
            'Since when Joshua become our employee?'
        );

        $this->assertStringContainsString('date hired', $answer);
        $this->assertStringContainsString('mar 15, 2020', $answer);
    }

    public function test_named_daily_attendance_question_does_not_return_general_on_duty_list(): void
    {
        $employee = $this->createJoshua();
        $yesterday = now()->subDay()->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'time_in' => $yesterday,
            'time_out' => $yesterday->copy()->addHours(8),
        ]);

        $answer = $this->askHr('Pumasok ba si Joshua ngayon?');

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('not clocked in today', $answer);
        $this->assertStringNotContainsString('nobody is on duty right now', $answer);
    }

    public function test_last_attendance_question_returns_latest_clock_in(): void
    {
        $employee = $this->createJoshua();
        $latestClockIn = now()->subDays(2)->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'time_in' => $latestClockIn,
            'time_out' => $latestClockIn->copy()->addHours(8),
        ]);

        $answer = $this->askHr('Kailan huling pumasok si Joshua?');

        $this->assertStringContainsString('last clocked in', $answer);
        $this->assertStringContainsString(
            mb_strtolower($latestClockIn->format('M j, Y')),
            $answer
        );
    }
}
