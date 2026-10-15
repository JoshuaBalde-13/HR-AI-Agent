<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotAttendanceQuestionsTest extends TestCase
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

    public function test_hr_can_ask_if_a_named_employee_clocked_in_today(): void
    {
        $clockIn = today()->setTime(8, 0);
        $clockOut = $clockIn->copy()->addHours(8);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $clockIn,
            'time_out' => $clockOut,
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Pumasok ba si Joshua ngayon?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('clocked in today', $answer);
    }

    public function test_hr_can_ask_when_a_named_employee_last_clocked_in(): void
    {
        $latestClockIn = now()->subHours(3);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => now()->subDays(2)->setTime(7, 0),
            'time_out' => now()->subDays(2)->setTime(16, 0),
        ]);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $latestClockIn,
            'time_out' => $latestClockIn->copy()->addHours(8),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Kailan huling pumasok si Joshua?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('last clock-in', $answer);

        $expectedDate = mb_strtolower(
            $latestClockIn->format('M j, Y')
        );

        $this->assertStringContainsString(
            $expectedDate,
            $answer
        );
    }

    public function test_hr_can_ask_when_an_employee_was_last_clocked_in_using_english(): void
    {
        $latestClockIn = now()->subHours(3);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => now()->subDays(2)->setTime(7, 0),
            'time_out' => now()->subDays(2)->setTime(16, 0),
        ]);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $latestClockIn,
            'time_out' => $latestClockIn->copy()->addHours(8),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'When was Joshua last clocked in?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('last clock-in', $answer);
        $this->assertStringContainsString(
            mb_strtolower($latestClockIn->format('M j, Y')),
            $answer
        );
    }

    public function test_hr_can_ask_whether_a_named_employee_is_currently_on_duty(): void
    {
        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => now()->subHours(2),
            'time_out' => null,
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Is Joshua on duty right now?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('currently on duty', $answer);
    }

    public function test_hr_can_count_a_named_employees_attendance_records(): void
    {
        foreach ([1, 2, 3] as $daysAgo) {
            $clockIn = now()
                ->subDays($daysAgo)
                ->setTime(8, 0);

            AttendanceLog::create([
                'employee_name' => 'Joshua Balde',
                'time_in' => $clockIn,
                'time_out' => $clockIn->copy()->addHours(8),
            ]);
        }

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Ilang beses pumasok si Joshua?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('3 attendance record(s)', $answer);
    }

    public function test_hr_is_told_when_the_named_employee_has_no_attendance_today(): void
    {
        $clockIn = now()->subDay()->setTime(8, 0);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $clockIn,
            'time_out' => $clockIn->copy()->addHours(8),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Pumasok ba si Joshua ngayon?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);

        $this->assertStringContainsString(
            'no attendance record for today',
            $answer
        );
    }

    public function test_hr_can_count_attendance_linked_by_employee_id_when_legacy_name_differs(): void
    {
        $employee = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        $clockIn = today()->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'time_in' => $clockIn,
            'time_out' => $clockIn->copy()->addHours(8),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Ilang beses pumasok si Joshua?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('1 attendance record(s)', $answer);
    }

    public function test_hr_can_get_worked_hours_by_employee_id_when_legacy_name_differs(): void
    {
        $employee = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        $clockIn = now()->subDays(1)->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'time_in' => $clockIn,
            'time_out' => $clockIn->copy()->addHours(8),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'hours of Joshua',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('worked hours', $answer);
        $this->assertStringContainsString('8 h', $answer);
    }



    public function test_general_on_duty_summary_uses_official_employee_names_and_counts_each_employee_once(): void
    {
        $employee = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        $morningClockIn = today()->copy()->setTime(8, 0);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Joshua Balde',
            'time_in' => $morningClockIn,
            'time_out' => $morningClockIn->copy()->addHours(8),
        ]);

        AttendanceLog::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'time_in' => now()->subHours(2),
            'time_out' => null,
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Who is on duty right now?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde, since', $answer);
        $this->assertStringNotContainsString('legacy export name', $answer);
        $this->assertStringContainsString(
            '1 employee(s) clocked in today',
            $answer
        );
    }

}