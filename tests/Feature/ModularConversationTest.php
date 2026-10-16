<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\AI\AssistantContext;
use App\Services\AI\ConversationMemory;
use App\Services\AI\HRISAssistantService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModularConversationTest extends TestCase
{
    use RefreshDatabase;

    private function hrContext(string $session): AssistantContext
    {
        return new AssistantContext(
            'HR',
            null,
            null,
            'dashboard',
            $session
        );
    }

    public function test_what_about_last_week_reuses_the_previous_question(): void
    {
        $lastWeek = today()->subWeek()->startOfWeek()
            ->addDays(1)->setTime(8, 0);

        $thisWeek = today()->startOfWeek()
            ->addDays(1)->setTime(8, 0);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $lastWeek,
            'time_out' => $lastWeek->copy()->addHours(3),
        ]);

        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => $thisWeek,
            'time_out' => $thisWeek->copy()->addHours(8),
        ]);

        $assistant = app(HRISAssistantService::class);
        $context = $this->hrContext('follow-up-hours-' . uniqid());

        $assistant->ask($context, 'How many hours did Joshua work?');

        $answer = $assistant->ask($context, 'What about last week?');
        $text = mb_strtolower(
            json_encode($answer, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $text);
        $this->assertStringContainsString('3 h last week', $text);
    }

    public function test_pronoun_follow_up_remembers_the_last_named_employee(): void
    {
        $joshua = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
            'date_hired' => '2020-03-15',
        ]);

        Employee::create([
            'name' => 'Kym Mendroga',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        EmployeeDocument::create([
            'employee_id' => $joshua->id,
            'employee_name' => 'Joshua Balde',
            'document_type' => 'Government ID',
            'expiry_date' => today()->addDays(90),
        ]);

        $assistant = app(HRISAssistantService::class);
        $context = $this->hrContext('follow-up-person-' . uniqid());

        $assistant->ask($context, "What is Joshua's position?");

        $memory = app(ConversationMemory::class);
        $this->assertSame('Joshua Balde', $memory->lastPerson($context));

        $answer = $assistant->ask(
            $context,
            'Kumpleto ba documents niya?'
        );

        $text = mb_strtolower(
            json_encode($answer, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $text);
        $this->assertStringNotContainsString('kym mendroga', $text);
    }

    public function test_conversation_memory_is_isolated_by_session_and_role(): void
    {
        $memory = app(ConversationMemory::class);

        $hrSessionA = $this->hrContext('memory-session-a-' . uniqid());
        $hrSessionB = $this->hrContext('memory-session-b-' . uniqid());

        $employeeSessionA = new AssistantContext(
            'Employee',
            'Joshua Balde',
            123,
            'dashboard',
            $hrSessionA->sessionKey
        );

        $memory->remember(
            $hrSessionA,
            'worked hours of Joshua Balde',
            'Joshua Balde'
        );

        $this->assertSame(
            'Joshua Balde',
            $memory->lastPerson($hrSessionA)
        );

        $this->assertNull($memory->lastPerson($hrSessionB));
        $this->assertNull($memory->lastPerson($employeeSessionA));

        $this->assertNull($memory->lastQuestion($hrSessionB));
        $this->assertNull($memory->lastQuestion($employeeSessionA));
    }

    public function test_switching_named_employee_updates_context_for_pronoun_follow_up(): void
    {
        $joshua = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
            'date_hired' => '2020-03-15',
        ]);

        Employee::create([
            'name' => 'Kym Mendroga',
            'position' => 'Team Leader',
            'employment_status' => 'Active',
            'date_hired' => '2021-05-10',
        ]);

        EmployeeDocument::create([
            'employee_id' => $joshua->id,
            'employee_name' => 'Joshua Balde',
            'document_type' => 'Government ID',
            'expiry_date' => today()->addDays(90),
        ]);

        $assistant = app(HRISAssistantService::class);
        $context = $this->hrContext('switch-person-' . uniqid());

        $assistant->ask($context, "What is Joshua's position?");
        $assistant->ask($context, "What is Kym's position?");

        $answer = $assistant->ask($context, 'Kumpleto ba documents niya?');

        $text = mb_strtolower(
            json_encode($answer, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('kym mendroga', $text);
        $this->assertStringNotContainsString('joshua balde', $text);
    }

    public function test_unknown_employee_question_does_not_reuse_previous_person(): void
    {
        config(['ollama.enabled' => false]);

        $joshua = Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
            'date_hired' => '2020-03-15',
        ]);

        EmployeeDocument::create([
            'employee_id' => $joshua->id,
            'employee_name' => 'Joshua Balde',
            'document_type' => 'Government ID',
            'expiry_date' => today()->addDays(90),
        ]);

        $assistant = app(HRISAssistantService::class);
        $context = $this->hrContext('unknown-person-' . uniqid());

        $assistant->ask($context, "What is Joshua's position?");

        $unknownAnswer = $assistant->ask(
            $context,
            "What is Alex's position?"
        );

        $unknownText = mb_strtolower(
            json_encode($unknownAnswer, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringNotContainsString('joshua balde', $unknownText);

        $followUp = $assistant->ask(
            $context,
            'Kumpleto ba documents niya?'
        );

        $followUpText = mb_strtolower(
            json_encode($followUp, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringNotContainsString('joshua balde', $followUpText);
    }


    public function test_ambiguous_employee_clarification_preserves_original_question(): void
    {
        config(['ollama.enabled' => false]);

        Employee::create([
            'name' => 'Joshua Balde',
            'position' => 'Security Guard',
            'employment_status' => 'Active',
        ]);

        Employee::create([
            'name' => 'Joshua Santos',
            'position' => 'Operations Supervisor',
            'employment_status' => 'Active',
        ]);

        $assistant = app(HRISAssistantService::class);
        $context = $this->hrContext('clarification-follow-up-' . uniqid());

        $clarification = $assistant->ask(
            $context,
            'What position does Joshua have?'
        );

        $clarificationText = mb_strtolower(
            json_encode($clarification, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua balde', $clarificationText);
        $this->assertStringContainsString('joshua santos', $clarificationText);

        $this->assertTrue(
            str_contains($clarificationText, 'which employee')
                || str_contains($clarificationText, 'more than one match')
                || str_contains($clarificationText, 'full name')
                || str_contains($clarificationText, 'which one'),
            "Expected a clarification question. Answer: {$clarificationText}"
        );

        $answer = $assistant->ask($context, 'Joshua Santos');

        $answerText = mb_strtolower(
            json_encode($answer, JSON_UNESCAPED_UNICODE)
        );

        $this->assertStringContainsString('joshua santos', $answerText);
        $this->assertStringContainsString('operations supervisor', $answerText);
        $this->assertStringNotContainsString('joshua balde', $answerText);
    }

}
