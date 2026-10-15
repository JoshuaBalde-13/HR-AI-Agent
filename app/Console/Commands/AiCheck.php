<?php

namespace App\Console\Commands;

use App\Services\AI\ApplicantAnswers;
use App\Services\AI\AssistantContext;
use App\Services\AI\AttendanceAnswers;
use App\Services\AI\ComplianceAnswers;
use App\Services\AI\ConversationMemory;
use App\Services\AI\HRISAssistantService;
use App\Services\AI\IntentRouter;
use App\Services\AI\PeopleAnswers;
use App\Services\AI\RuleAnswers;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AiCheck extends Command
{
    private const EXPECTED_VERSION = 5;

    protected $signature = 'ai:check {--ai : Also test the AI router (needs Ollama)}';

    protected $description = 'Check that every assistant file is up to date, then run sample questions';

    public function handle(): int
    {
        // 1. Is every file the latest one?
        $files = [
            'app/Services/AI/RuleAnswers.php' => RuleAnswers::class,
            'app/Services/AI/AttendanceAnswers.php' => AttendanceAnswers::class,
            'app/Services/AI/ComplianceAnswers.php' => ComplianceAnswers::class,
            'app/Services/AI/ApplicantAnswers.php' => ApplicantAnswers::class,
            'app/Services/AI/PeopleAnswers.php' => PeopleAnswers::class,
            'app/Services/AI/IntentRouter.php' => IntentRouter::class,
            'app/Services/AI/ConversationMemory.php' => ConversationMemory::class,
            'app/Services/AI/HRISAssistantService.php' => HRISAssistantService::class,
        ];

        $status = [];
        $allGood = true;

        foreach ($files as $path => $class) {
            if (!class_exists($class)) {
                $status[] = [$path, 'MISSING'];
                $allGood = false;

                continue;
            }

            $version = defined($class . '::VERSION') ? constant($class . '::VERSION') : 0;

            if ($version !== self::EXPECTED_VERSION) {
                $status[] = [$path, "OLD (version {$version}, expected " . self::EXPECTED_VERSION . ')'];
                $allGood = false;

                continue;
            }

            $status[] = [$path, 'OK'];
        }

        $this->table(['File', 'Status'], $status);

        if (!$allGood) {
            $this->error('Some files are missing or old. Replace the files marked MISSING or OLD with the newest version, then run this again.');

            return self::FAILURE;
        }

        // 2. Sample questions.
        $withAi = (bool) $this->option('ai');

        // Without --ai nothing is sent to Ollama, so the check is fast and does not depend on the model.
        config(['ollama.enabled' => $withAi]);

        $assistant = app(HRISAssistantService::class);
        $session = 'check-' . uniqid();

        $contexts = [
            'HR' => new AssistantContext('HR', null, null, 'dashboard', $session),
            'Employee' => new AssistantContext('Employee', 'Juan Dela Cruz', null, 'dashboard', $session),
            'Applicant' => new AssistantContext('Applicant', null, null, 'dashboard', $session),
        ];

        // [role, question, text that must appear in the answer, needs the AI]
        // The order matters: "siya" refers to the person of the question before it.
        $tests = [
            ['HR', 'thank you', 'welcome', false],
            ['HR', 'may late ba ngayong araw', 'no shift schedule', false],
            ['HR', 'status ni kym mendroga', 'rejected', false],
            ['HR', 'may applicant ba tayong kym mendroga?', 'Yes, kym mendroga is on file as an applicant', false],
            ['HR', 'anong role ni joshua', 'Security Guard', false],
            ['HR', 'since when joshua become our employee?', 'employee since', false],
            ['HR', 'kumpleto ba documents ni joshua', 'Government ID', false],
            ['HR', 'may expired ba siyang document?', 'Security License', false],
            ['HR', 'pumasok ba si joshua ngayon?', 'not clocked in today', false],
            ['HR', 'kailan huling pumasok si joshua?', 'last clocked in', false],
            ['HR', 'ilang beses pumasok si joshua', 'came in', false],
            ['HR', 'which employees have incomplete documents?', 'Joshua Balde', false],
            ['HR', 'how many active employees do we have?', 'active employee', false],
            ['HR', 'sino ang pinakamaraming oras?', 'Juan Dela Cruz', false],
            ['HR', 'any expired documents?', 'Security License', false],
            ['HR', 'sino ang mga na reject?', 'kym mendroga', false],
            ['HR', 'ilan ang natanggap na aplikante?', 'approved', false],
            ['HR', 'ilan nag apply kahapon?', 'yesterday', false],
            ['HR', 'last week?', 'last week', false],
            ['HR', 'paano mag-reject ng applicant?', 'Reject', false],
            ['Employee', 'sino si joshua', 'own records', false],
            ['Employee', 'anong position ko?', 'Security Guard', false],
            ['Employee', 'ilang beses na ako pumasok?', 'came in', false],
            ['Employee', 'kumpleto ba documents ko?', 'complete', false],
            ['Applicant', "show me Joshua's employee record", 'own application', false],
            ['HR', 'sino ang mga nasa trabaho ngayon?', 'on duty', true],
            ['HR', 'ano ang mga dapat kong unahin ngayon?', 'attention', true],
        ];

        $rows = [];
        $passed = 0;
        $total = 0;

        foreach ($tests as [$role, $question, $expected, $needsAi]) {
            if ($needsAi && !$withAi) {
                continue;
            }

            $started = microtime(true);
            $result = $assistant->ask($contexts[$role], $question);
            $ms = (int) round((microtime(true) - $started) * 1000);

            $lines = collect($result['items'] ?? [])->pluck('text');

            $haystack = Str::lower(
                ($result['title'] ?? '') . ' ' . $lines->implode(' ') . ' ' . ($result['note'] ?? '')
            );

            $ok = str_contains($haystack, Str::lower($expected));

            $total++;
            $passed += $ok ? 1 : 0;

            $rows[] = [
                $ok ? 'PASS' : 'FAIL',
                $ms,
                $role,
                Str::limit($question, 38),
                Str::limit((string) $lines->first(), 70),
            ];
        }

        $this->table(['Result', 'ms', 'Role', 'Question', 'Answer starts with'], $rows);

        $this->line("{$passed} of {$total} passed.");

        if ($passed < $total) {
            $this->warn('A FAIL can also mean your data is different (names, documents, dates). Send me the FAIL rows.');
        }

        return $passed === $total ? self::SUCCESS : self::FAILURE;
    }
}