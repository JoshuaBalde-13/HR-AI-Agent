<?php

namespace App\Services\AI;

use App\Models\Applicant;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\OllamaClient;
use Illuminate\Support\Facades\Log;

/**
 * Turns a free-form message into ONE command that the rules already understand.
 *
 * The AI never writes an answer and never touches the database. It only picks
 * an intent (and a person, a period, a status). The app builds a plain
 * question from that and the rules answer it with exact data.
 */
class IntentRouter
{
    public const VERSION = 5;

    private const PERIODS = ['all', 'today', 'yesterday', 'this_week', 'this_month', 'last_week', 'last_month'];

    private const STATUSES = ['any', 'approved', 'rejected', 'pending', 'interview', 'in_progress'];

    private const FOCUS = ['profile', 'role', 'employment', 'hired', 'decision', 'interview', 'completeness', 'documents', 'hours', 'attendance', 'status'];

    private const SCOPES = ['expiring', 'expired', 'incomplete'];

    private const RANKING = ['none', 'most', 'least'];

    private const PRONOUNS = ['siya', 'siyang', 'niya', 'niyang', 'nya', 'kanya', 'kanyang', 'he', 'she', 'his', 'her', 'him'];

    public function __construct(
        private OllamaClient $ollama,
        private AnswerGuard $guard,
    ) {
    }

    /**
     * @return array{intent: string, canonical: ?string}|null  null when the AI could not be reached
     */
    public function route(string $question, string $role, ?string $previous = null, ?string $lastPerson = null): ?array
    {
        $names = $this->names($role);

        $content = $this->ollama->chat(
            [
                ['role' => 'system', 'content' => $this->system($role, $names)],
                ['role' => 'user', 'content' => $this->userMessage($question, $previous, $lastPerson)],
            ],
            $this->schema($role),
            (int) config('ollama.router_tokens', 90),
            0
        );

        if ($content === null) {
            return null;
        }

        $fields = json_decode($content, true);

        if (!is_array($fields)) {
            Log::warning('Intent router gave an unreadable answer.');

            return ['intent' => 'unknown', 'canonical' => null];
        }

        $intent = is_string($fields['intent'] ?? null) ? $fields['intent'] : 'unknown';

        if ($intent === 'unknown' || !in_array($intent, $this->intents($role), true)) {
            return ['intent' => 'unknown', 'canonical' => null];
        }

        // The model must point at words that are really in the message.
        if (!$this->hasEvidence($question, (string) ($fields['evidence'] ?? ''))) {
            Log::info('Intent router guard: no evidence', ['intent' => $intent]);

            return ['intent' => 'unknown', 'canonical' => null];
        }

        // "siya": the model may leave the person empty. Use the last person talked about.
        $fallbackPerson = ($lastPerson && $this->guard->normalize($question) !== '' && $this->hasPronoun($question))
            ? $lastPerson
            : null;

        $canonical = $this->compose($role, $intent, $fields, $names, $fallbackPerson);

        // Only the intent is logged: the command can contain a person's name.
        Log::info('Intent router', ['role' => $role, 'intent' => $intent, 'understood' => $canonical !== null]);

        return ['intent' => $intent, 'canonical' => $canonical];
    }

    /*
    |--------------------------------------------------------------------------
    | What the model may choose from
    |--------------------------------------------------------------------------
    */

    private function intents(string $role): array
    {
        return match ($role) {
            'HR' => ['applicants', 'person', 'employees', 'documents', 'hours', 'on_duty', 'summary', 'howto', 'unknown'],
            'Employee' => ['hours', 'attendance', 'documents', 'employment', 'howto', 'unknown'],
            default => ['status', 'next_step', 'interview', 'howto', 'unknown'],
        };
    }

    private function topics(string $role): array
    {
        return match ($role) {
            'HR' => ['review_applicant', 'compliance'],
            'Employee' => ['renew_document'],
            default => ['apply'],
        };
    }

    // The answer must match this shape, so the model can only pick allowed values.
    private function schema(string $role): array
    {
        $properties = [
            'evidence' => ['type' => 'string'],
            'intent' => ['type' => 'string', 'enum' => $this->intents($role)],
        ];

        if ($role !== 'Applicant') {
            $properties['period'] = ['type' => 'string', 'enum' => self::PERIODS];
        }

        if ($role === 'HR') {
            $properties['person'] = ['type' => 'string'];
            $properties['focus'] = ['type' => 'string', 'enum' => self::FOCUS];
            $properties['status'] = ['type' => 'string', 'enum' => self::STATUSES];
            $properties['scope'] = ['type' => 'string', 'enum' => self::SCOPES];
            $properties['ranking'] = ['type' => 'string', 'enum' => self::RANKING];
        }

        if ($role === 'Employee') {
            $properties['scope'] = ['type' => 'string', 'enum' => ['all', 'expiring', 'completeness']];
        }

        $properties['topic'] = ['type' => 'string', 'enum' => $this->topics($role)];

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => ['evidence', 'intent'],
            'additionalProperties' => false,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt
    |--------------------------------------------------------------------------
    |
    | Kept short and stable: Ollama reuses its work on an identical start of the
    | prompt, so the second question is much faster than the first.
    |
    */

    private function system(string $role, array $names): string
    {
        $lines = [
            'You turn a message into one command for an HR system. Reply with ONE JSON object and nothing else. The message may be English, Filipino/Tagalog or mixed, with typos.',
            'Never answer the message yourself. Pick the "intent" that matches. If the message is not asking for HR system data, use "unknown".',
            '"evidence" is the exact words copied from the message that show the intent. Use "" when the intent is unknown.',
            '',
        ];

        if ($role === 'HR') {
            $lines = array_merge($lines, [
                'Intents:',
                '- applicants: counts or lists of job applicants. Use status and period.',
                '- person: anything about one named person (applicant or employee). Use person (the exact full name from KNOWN NAMES) and focus.',
                '- employees: how many employees there are, or who they are.',
                '- documents: employee documents. scope is expiring, expired or incomplete (missing required documents). period applies to expiring.',
                '- hours: worked hours of employees. person for one employee, ranking for who worked the most or the least.',
                '- on_duty: who is clocked in right now.',
                '- summary: what needs HR attention.',
                '- howto: how to use the system. topic is review_applicant or compliance.',
                'Status: approved (natanggap, nakapasa), rejected (hindi natanggap, bagsak), pending (naghihintay), interview, in_progress (still filling the form), any.',
                'Period: today, yesterday, this_week, this_month, last_week, last_month, all (no period said).',
                'If the message says "siya", "niya" or "he/she", use the "Last person mentioned" as person.',
            ]);
        } elseif ($role === 'Employee') {
            $lines = array_merge($lines, [
                'The user is an employee and may only ask about their own records.',
                'Intents: hours (own worked hours, use period), attendance (own time in and time out), documents (own documents, scope is all, expiring or completeness), employment (own position and date hired), howto (topic renew_document).',
                'Period: today, yesterday, this_week, this_month, last_week, last_month, all (no period said).',
            ]);
        } else {
            $lines = array_merge($lines, [
                'The user is a job applicant and may only ask about their own application.',
                'Intents: status (where the application stands), next_step (what to do next), interview (when or where the interview is), howto (topic apply).',
            ]);
        }

        $lines[] = '';
        $lines[] = 'Examples:';

        foreach ($this->examples($role, $names) as $example) {
            $lines[] = $example;
        }

        if ($role === 'HR') {
            $lines[] = 'KNOWN NAMES: ' . ($names ? implode(', ', $names) : 'none') . '.';
        }

        return implode("\n", $lines);
    }

    private function example(string $message, array $fields, ?string $previous = null): string
    {
        $text = $previous !== null ? "Previous question: {$previous}\n" : '';

        return $text . "Message: {$message}\n" . json_encode($fields, JSON_UNESCAPED_UNICODE) . "\n";
    }

    private function examples(string $role, array $names): array
    {
        if ($role === 'HR') {
            $list = [
                $this->example('ilan ang natanggap noong nakaraang buwan?', ['evidence' => 'natanggap noong nakaraang buwan', 'intent' => 'applicants', 'status' => 'approved', 'period' => 'last_month']),
                $this->example('sino ang may kulang na papeles?', ['evidence' => 'kulang na papeles', 'intent' => 'documents', 'scope' => 'incomplete']),
                $this->example('sino ang pinaka matagal nag-duty ngayong buwan?', ['evidence' => 'pinaka matagal nag-duty', 'intent' => 'hours', 'ranking' => 'most', 'period' => 'this_month']),
                $this->example('sino ang nasa trabaho ngayon?', ['evidence' => 'nasa trabaho ngayon', 'intent' => 'on_duty']),
                $this->example('ano ang dapat kong unahin?', ['evidence' => 'dapat kong unahin', 'intent' => 'summary']),
                $this->example('kahapon naman?', ['evidence' => 'kahapon', 'intent' => 'applicants', 'status' => 'any', 'period' => 'yesterday'], 'How many applicants today?'),
            ];

            if ($names) {
                $full = $names[0];
                $first = explode(' ', $full)[0];

                $list[] = $this->example("kumusta na si {$first}?", ['evidence' => "si {$first}", 'intent' => 'person', 'person' => $full, 'focus' => 'profile']);
                $list[] = $this->example("kailan na-hire si {$first}?", ['evidence' => 'na-hire', 'intent' => 'person', 'person' => $full, 'focus' => 'hired']);
            }

            $list[] = $this->example('magkano ang presyo ng bigas?', ['evidence' => '', 'intent' => 'unknown']);

            return $list;
        }

        if ($role === 'Employee') {
            return [
                $this->example('ilang oras na ako nag-duty ngayong linggo?', ['evidence' => 'ilang oras na ako nag-duty', 'intent' => 'hours', 'period' => 'this_week']),
                $this->example('kumpleto na ba ang papeles ko?', ['evidence' => 'kumpleto na ba ang papeles', 'intent' => 'documents', 'scope' => 'completeness']),
                $this->example('ano ang posisyon ko?', ['evidence' => 'posisyon ko', 'intent' => 'employment']),
                $this->example('paano mag-renew ng papeles?', ['evidence' => 'mag-renew', 'intent' => 'howto', 'topic' => 'renew_document']),
                $this->example('magkano ang sahod ko?', ['evidence' => '', 'intent' => 'unknown']),
            ];
        }

        return [
            $this->example('nasaan na ang application ko?', ['evidence' => 'application ko', 'intent' => 'status']),
            $this->example('ano ang gagawin ko ngayon?', ['evidence' => 'gagawin ko', 'intent' => 'next_step']),
            $this->example('kailan ang interview ko?', ['evidence' => 'interview ko', 'intent' => 'interview']),
            $this->example('paano ako mag-aapply?', ['evidence' => 'mag-aapply', 'intent' => 'howto', 'topic' => 'apply']),
            $this->example('magkano ang sahod?', ['evidence' => '', 'intent' => 'unknown']),
        ];
    }

    private function userMessage(string $question, ?string $previous, ?string $lastPerson): string
    {
        $lines = [];

        if ($previous !== null && $previous !== '') {
            $lines[] = 'Previous question: ' . mb_substr($previous, 0, 200);
        }

        if ($lastPerson !== null && $lastPerson !== '') {
            $lines[] = 'Last person mentioned: ' . $lastPerson;
        }

        $lines[] = 'Message: ' . mb_substr($question, 0, 300);

        return implode("\n", $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Checking and building the command
    |--------------------------------------------------------------------------
    */

    // At least one word of the evidence must really be in the message.
    private function hasEvidence(string $question, string $evidence): bool
    {
        $message = ' ' . $this->guard->normalize($question) . ' ';

        foreach (explode(' ', $this->guard->normalize($evidence)) as $word) {
            if (mb_strlen($word) >= 3 && str_contains($message, " {$word} ")) {
                return true;
            }
        }

        return false;
    }

    private function hasPronoun(string $question): bool
    {
        $words = explode(' ', $this->guard->normalize($question));

        return count(array_intersect($words, self::PRONOUNS)) > 0;
    }

    // The known people, so the model can only name someone who exists.
    private function names(string $role): array
    {
        if ($role !== 'HR') {
            return [];
        }

        return Employee::query()->pluck('name')
            ->merge(AttendanceLog::query()->distinct()->pluck('employee_name'))
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->merge(Applicant::query()->pluck('name'))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->take(40)
            ->all();
    }

    private function resolvePerson(string $person, array $names, ?string $fallback): ?string
    {
        $person = mb_strtolower(trim($person));

        if ($person !== '') {
            foreach ($names as $name) {
                if (mb_strtolower($name) === $person) {
                    return $name;
                }
            }

            // A first or last name that matches exactly one known person.
            $matches = array_values(array_filter(
                $names,
                fn ($name) => str_contains(mb_strtolower($name), $person)
            ));

            if (count($matches) === 1) {
                return $matches[0];
            }
        }

        return $fallback;
    }

    private function periodWords(string $period): string
    {
        return match ($period) {
            'today' => 'today',
            'yesterday' => 'yesterday',
            'this_week' => 'this week',
            'this_month' => 'this month',
            'last_week' => 'last week',
            'last_month' => 'last month',
            default => '',
        };
    }

    // The plain question the rules understand.
    private function compose(string $role, string $intent, array $f, array $names, ?string $fallbackPerson): ?string
    {
        $period = $this->periodWords((string) ($f['period'] ?? 'all'));

        if ($role === 'HR') {
            return match ($intent) {
                'applicants' => trim($this->applicantsPhrase((string) ($f['status'] ?? 'any')) . " {$period}"),
                'person' => $this->personCommand($f, $names, $period, $fallbackPerson),
                'employees' => 'how many employees do we have',
                'documents' => $this->documentsCommand((string) ($f['scope'] ?? 'expiring'), (string) ($f['period'] ?? 'all')),
                'hours' => $this->hoursCommand($f, $names, $period, $fallbackPerson),
                'on_duty' => 'who is on duty now',
                'summary' => 'what needs my attention',
                'howto' => $this->howtoCommand((string) ($f['topic'] ?? '')),
                default => null,
            };
        }

        if ($role === 'Employee') {
            return match ($intent) {
                'hours' => trim("my worked hours {$period}"),
                'attendance' => trim("my attendance {$period}"),
                'documents' => match ((string) ($f['scope'] ?? 'all')) {
                    'expiring' => 'which of my documents expire soon',
                    'completeness' => 'are my documents complete',
                    default => 'my documents',
                },
                'employment' => 'what is my position',
                'howto' => $this->howtoCommand((string) ($f['topic'] ?? '')),
                default => null,
            };
        }

        return match ($intent) {
            'status' => 'what is my application status',
            'next_step' => 'what is my next step',
            'interview' => 'when is my interview',
            'howto' => $this->howtoCommand((string) ($f['topic'] ?? '')),
            default => null,
        };
    }

    private function applicantsPhrase(string $status): string
    {
        return match ($status) {
            'approved' => 'how many approved applicants',
            'rejected' => 'how many rejected applicants',
            'pending' => 'pending applications',
            'interview' => 'applicants with an interview',
            'in_progress' => 'applications in progress',
            default => 'how many applicants',
        };
    }

    private function personCommand(array $f, array $names, string $period, ?string $fallback): ?string
    {
        $person = $this->resolvePerson((string) ($f['person'] ?? ''), $names, $fallback);

        if ($person === null) {
            return null;
        }

        return match ((string) ($f['focus'] ?? 'profile')) {
            'role' => "what is the position of {$person}",
            'employment', 'hired' => "since when is {$person} an employee, date hired",
            'decision' => "when was {$person} approved or rejected",
            'interview' => "interview of {$person}",
            'completeness' => "are the documents of {$person} complete",
            'documents' => "documents of {$person}",
            'hours' => trim("worked hours of {$person} {$period}"),
            'attendance' => trim("attendance of {$person} {$period}"),
            'status' => "status of {$person}",
            default => "tell me about {$person}",
        };
    }

    private function hoursCommand(array $f, array $names, string $period, ?string $fallback): string
    {
        $person = $this->resolvePerson((string) ($f['person'] ?? ''), $names, $fallback);

        if ($person !== null) {
            return trim("worked hours of {$person} {$period}");
        }

        return match ((string) ($f['ranking'] ?? 'none')) {
            'most' => trim("who worked the most {$period}"),
            'least' => trim("who worked the least {$period}"),
            default => trim("worked hours {$period}"),
        };
    }

    private function documentsCommand(string $scope, string $period): string
    {
        return match ($scope) {
            'expired' => 'any expired documents',
            'incomplete' => 'which employees have incomplete documents',
            default => 'documents expiring ' . match ($period) {
                'this_week' => 'this week',
                'this_month' => 'this month',
                default => 'within 30 days',
            },
        };
    }

    private function howtoCommand(string $topic): ?string
    {
        return match ($topic) {
            'apply' => 'how to apply for a job',
            'renew_document' => 'how to renew a document',
            'review_applicant' => 'how to review an applicant',
            'compliance' => 'how to use the compliance page',
            default => null,
        };
    }
}