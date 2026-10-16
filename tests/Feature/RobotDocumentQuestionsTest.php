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
    public function test_document_expiry_report_respects_the_30_day_boundary(): void
    {
        config(['hris.warning_days' => 30]);

        $joshua = Employee::create([
            'name' => 'Joshua Balde',
        ]);

        $kym = Employee::create([
            'name' => 'Kym Mendroga',
        ]);

        EmployeeDocument::create([
            'employee_id' => $joshua->id,
            'employee_name' => 'Joshua Balde',
            'document_type' => 'NBI Clearance',
            'expiry_date' => today()->subDay(),
        ]);

        EmployeeDocument::create([
            'employee_id' => $kym->id,
            'employee_name' => 'Kym Mendroga',
            'document_type' => 'Government ID',
            'expiry_date' => today(),
        ]);

        EmployeeDocument::create([
            'employee_id' => $kym->id,
            'employee_name' => 'Kym Mendroga',
            'document_type' => 'Medical Certificate',
            'expiry_date' => today()->addDays(30),
        ]);

        EmployeeDocument::create([
            'employee_id' => $joshua->id,
            'employee_name' => 'Joshua Balde',
            'document_type' => 'Security Training Certificate',
            'expiry_date' => today()->addDays(31),
        ]);

        $response = $this->actingAs($this->hr, 'web')
            ->postJson(route('robot.ask'), [
                'question' => 'Any expired documents?',
                'role' => 'HR',
                'employee_name' => null,
            ]);

        $response->assertOk();

        $answer = mb_strtolower(
            json_encode($response->json(), JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('1 expired', $answer);
        $this->assertStringContainsString(
            '2 expiring within 30 days',
            $answer
        );
        $this->assertStringContainsString(
            'joshua balde, nbi clearance (1 day(s) overdue)',
            $answer
        );
        $this->assertStringContainsString(
            'kym mendroga, government id (0 day(s) left)',
            $answer
        );
        $this->assertStringContainsString(
            'kym mendroga, medical certificate (30 day(s) left)',
            $answer
        );
        $this->assertStringNotContainsString(
            'security training certificate',
            $answer
        );
    }
}
