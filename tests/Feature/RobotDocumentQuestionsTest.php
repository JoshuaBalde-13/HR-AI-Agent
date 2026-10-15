<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RobotDocumentQuestionsTest extends TestCase
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

    public function test_hr_gets_a_clear_answer_when_employee_has_no_documents(): void
    {
        Employee::create([
            'name' => 'Joshua Balde',
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Show documents for Joshua',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('no documents on file yet', $answer);
    }

    public function test_hr_is_asked_to_clarify_an_ambiguous_employee_name(): void
    {
        Employee::create([
            'name' => 'Joshua Balde',
        ]);

        Employee::create([
            'name' => 'Joshua Santos',
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Show documents for Joshua',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('which employee', $answer);
        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('joshua santos', $answer);
    }

    public function test_hr_can_find_documents_by_employee_id_when_legacy_name_differs(): void
    {
        $employee = Employee::create([
            'name' => 'Joshua Balde',
        ]);

        EmployeeDocument::create([
            'employee_id' => $employee->id,
            'employee_name' => 'Legacy Export Name',
            'document_type' => 'Government ID',
            'expiry_date' => today()->addDays(90),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Show documents for Joshua',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $answer);
        $this->assertStringContainsString('government id', $answer);
        $this->assertStringContainsString('valid until', $answer);
    }
}
