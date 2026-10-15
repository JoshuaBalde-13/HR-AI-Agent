<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StaffSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_from_hr_dashboard_to_staff_login(): void
    {
        $response = $this->get(route('hr.dashboard'));

        $response->assertRedirect(route('staff.login'));
    }

    public function test_guest_cannot_use_robot_endpoint(): void
    {
        $response = $this->postJson(route('robot.ask'), [
            'question' => 'Who am I?',
            'role' => 'HR',
            'employee_name' => 'Juan Dela Cruz',
        ]);

        $response->assertUnauthorized();
    }

    public function test_security_user_cannot_access_hr_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_SECURITY,
        ]);

        $response = $this->actingAs($user, 'web')
            ->get(route('hr.dashboard'));

        $response->assertForbidden();
    }

    public function test_hr_staff_login_redirects_to_hr_dashboard(): void
    {
        $user = User::factory()->create([
            'role' => User::ROLE_HR,
            'password' => Hash::make('test-password'),
        ]);

        $response = $this->post(route('staff.login.submit'), [
            'email' => $user->email,
            'password' => 'test-password',
        ]);

        $response->assertRedirect(route('hr.dashboard'));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_robot_uses_authenticated_employee_identity_not_payload(): void
    {
        $user = User::factory()->create([
            'name' => 'Joshua Balde',
            'role' => User::ROLE_SECURITY,
        ]);

        Employee::create([
            'user_id' => $user->id,
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
            'date_hired' => today()->toDateString(),
        ]);

        $response = $this->actingAs($user, 'web')->postJson(
            route('robot.ask'),
            [
                'question' => 'Who am I?',
                'role' => 'HR',
                'employee_name' => 'Juan Dela Cruz',
            ]
        );

        $response->assertOk();

        $body = json_encode(
            $response->json(),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringContainsString('Joshua Balde', $body);
        $this->assertStringNotContainsString('Juan Dela Cruz', $body);
    }

    public function test_applicant_cannot_claim_hr_role_in_robot_request(): void
    {
        $applicant = Applicant::create([
            'name' => 'Maria Santos',
            'email' => 'maria.security-test@example.com',
            'password' => Hash::make('test-password'),
        ]);

        $response = $this->actingAs($applicant, 'applicant')->postJson(
            route('robot.ask'),
            [
                'question' => 'Who am I?',
                'role' => 'HR',
                'employee_name' => 'Juan Dela Cruz',
            ]
        );

        $response->assertOk();

        $body = json_encode(
            $response->json(),
            JSON_UNESCAPED_UNICODE
        );

        $this->assertStringContainsString('Maria Santos', $body);
        $this->assertStringNotContainsString('Juan Dela Cruz', $body);
    }
}