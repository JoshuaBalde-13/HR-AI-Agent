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
    /**
     * Each file maintains its own version.
     *
     * Update the matching entry when intentionally bumping that file's
     * VERSION constant. Do not force every service to use the same number.
     */
    private const EXPECTED_VERSIONS = [
        RuleAnswers::class => 7,
        AttendanceAnswers::class => 5,
        ComplianceAnswers::class => 5,
        ApplicantAnswers::class => 5,
        PeopleAnswers::class => 5,
        IntentRouter::class => 5,
        ConversationMemory::class => 5,
        HRISAssistantService::class => 5,
    ];

    protected $signature = 'ai:check {--ai : Also test the AI router (needs Ollama)}';

    protected $description = 'Check assistant files and run sample questions';

    public function handle(): int
    {
        /*
        |--------------------------------------------------------------------------
        | 1. Verify files and their individual versions
        |--------------------------------------------------------------------------
        */

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

            $expectedVersion = self::EXPECTED_VERSIONS[$class] ?? null;

            if ($expectedVersion === null) {
                $status[] = [$path, 'NO EXPECTED VERSION'];
                $allGood = false;

                continue;
            }

            $versionConstant = $class . '::VERSION';

            if (!defined($versionConstant)) {
                $status[] = [$path, 'MISSING VERSION'];
                $allGood = false;

                continue;
            }

            $actualVersion = constant($versionConstant);

            if ($actualVersion !== $expectedVersion) {
                $status[] = [
                    $path,
                    "VERSION MISMATCH (actual {$actualVersion}, expected {$expectedVersion})",
                ];

                $allGood = false;

                continue;
            }

            $status[] = [$path, "OK (v{$actualVersion})"];
        }

        $this->table(['File', 'Status'], $status);

        if (!$allGood) {
            $this->error(
                'Some assistant files are missing or have unexpected versions. '
                . 'Review the files marked above before running the sample questions.'
            );

            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | 2. Run sample questions
        |--------------------------------------------------------------------------
        */

        $withAi = (bool) $this->option('ai');

        // Without --ai, tests do not call Ollama.
        config(['ollama.enabled' => $withAi]);

        $assistant = app(HRISAssistantService::class);
        $session = 'check-' . uniqid();

        $contexts = [
            'HR' => new AssistantContext(
                'HR',
                null,
                null,
                'dashboard',
                $session
            ),
            'Employee' => new AssistantContext(
                'Employee',
                'Juan Dela Cruz',
                null,
                'dashboard',
                $session
            ),
            'Applicant' => new AssistantContext(
                'Applicant',
                null,
                null,
                'dashboard',
                $session
            ),
        ];

        // [role, question, expected answer text, needs AI]
        // Order matters: "siya" refers to the person discussed previously.
        $tests = [
            ['HR', 'thank you', 'welcome', false],
            ['HR', 'may late ba ngayong araw', 'no shift schedule', false],
            ['HR', 'status ni kym mendroga', 'rejected', false],
            ['HR', 'may applicant ba tayong kym mendroga?', 'Yes, kym mendroga is on file as an applicant', false],
            ['HR', 'anong role ni joshua', 'Security Guard', false],
            ['HR', 'since when joshua become our employee?', 'date hired', false],
            ['HR', 'kumpleto ba documents ni joshua', 'Government ID', false],
            ['HR', 'may expired ba siyang document?', 'Security License', false],
            ['HR', 'pumasok ba si joshua ngayon?', 'not clocked in today', false],
            ['HR', 'kailan huling pumasok si joshua?', 'last clocked in', false],
            ['HR', 'ilang beses pumasok si joshua', 'finished shift', false],
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
            ['Employee', 'ilang beses na ako pumasok?', 'finished shift', false],
            ['Employee', 'kumpleto ba documents ko?', '4 of 4 required documents', false],
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

            $result = $assistant->ask(
                $contexts[$role],
                $question
            );

            $elapsedMs = (int) round(
                (microtime(true) - $started) * 1000
            );

            $lines = collect($result['items'] ?? [])->pluck('text');

            $haystack = Str::lower(
                ($result['title'] ?? '')
                . ' '
                . $lines->implode(' ')
                . ' '
                . ($result['note'] ?? '')
            );

            $ok = str_contains(
                $haystack,
                Str::lower($expected)
            );

            $total++;
            $passed += $ok ? 1 : 0;

            $rows[] = [
                $ok ? 'PASS' : 'FAIL',
                $elapsedMs,
                $role,
                Str::limit($question, 38),
                Str::limit((string) $lines->first(), 70),
            ];
        }

        $this->table(
            ['Result', 'ms', 'Role', 'Question', 'Answer starts with'],
            $rows
        );

        $this->line("{$passed} of {$total} passed.");

        if ($passed < $total) {
            $this->warn(
                'A failed sample may indicate a behavior regression or different database contents. '
                . 'Review the FAIL rows and their expected answers.'
            );
        }

        return $passed === $total
            ? self::SUCCESS
            : self::FAILURE;
    }
}
