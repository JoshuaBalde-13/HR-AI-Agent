<?php

namespace App\Services\AI;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\AttendanceLog;
use App\Models\EmployeeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Exact, deterministic answers. Every number comes straight from the database
 * and no AI is involved. Phases 5 to 7 split this class into the applicant,
 * HR information and compliance services.
 */
class RuleAnswers
{
    private const STATUS_LABELS = [
        'draft' => 'In progress',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'interview_scheduled' => 'Interview scheduled',
        'interview_completed' => 'Interview completed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // Specific on purpose, so a general chat question is not mistaken for data.
    private const HOURS_WORDS = [
        'worked', 'work hour', 'working hour', 'duty hour', 'total hour', 'how many hours',
        'hours of', 'hours ni', 'hours this', 'hours today', 'hours yesterday', 'my hours',
        'ilang oras', 'oras ni', 'oras ng', 'oras ang',
        'nagtrabaho', 'nag trabaho', 'nagwork', 'nag work',
    ];

    // "How many times did I come in?" is answered from the time in / time out records.
    private const ATTENDANCE_WORDS = [
        'ilang beses', 'ilang shift', 'how many times', 'how many shifts', 'shifts',
        'attendance', 'dtr', 'time in', 'time out', 'clock in',
    ];

    // Questions the system cannot answer because it has no shift schedule.
    private const SCHEDULE_WORDS = [
        'late', 'tardy', 'tardiness', 'absent', 'absence', 'absences',
        'undertime', 'overtime', 'nahuli', 'lumiban',
    ];

    private const SCHEDULE_PHRASES = ['shift schedule', 'duty schedule', 'work schedule'];

    private const DOCUMENT_WORDS = [
        'document', 'dokumento', 'expire', 'expiry', 'expired', 'license', 'nbi', 'medical', 'renew',
    ];

    private const COMPLETENESS_WORDS = [
        'kumpleto', 'complete', 'incomplete', 'missing', 'kulang', 'requirement',
    ];

    private const EMPLOYEE_WORDS = ['employee', 'empleyado', 'staff'];

    private const GREETING_WORDS = ['help', 'hi', 'hello', 'hey', 'kumusta', 'tulong'];

    // Common words that must never count as part of a person's name.
    private const NAME_STOP = [
        'the', 'and', 'for', 'who', 'how', 'many', 'any', 'are', 'there', 'our', 'has', 'have',
        'did', 'does', 'what', 'when', 'where', 'which', 'with', 'from', 'this', 'that', 'about',
        'tell', 'show', 'list', 'please', 'status', 'today', 'week', 'month', 'yesterday', 'tomorrow',
        'ito', 'yung', 'ang', 'mga', 'ng', 'sa', 'ni', 'kay', 'ba', 'na', 'po', 'ano', 'sino',
        'ilan', 'may', 'meron', 'mayroon', 'natin', 'namin', 'nila', 'siya', 'lahat', 'tungkol',
        'ngayon', 'kahapon', 'bukas', 'paki',
    ];

    /*
    |--------------------------------------------------------------------------
    | Entry point
    |--------------------------------------------------------------------------
    |
    | $q is the normalized question. $user is ['name' => ..., 'line' => ...].
    | Returns null when no rule understands the question.
    |
    */

    public function respond(string $role, string $q, array $user): ?array
    {
        if ($this->has($q, ['my name', 'who am i', 'sino ako', 'pangalan ko'])) {
            return $this->identity($role, $user['name']);
        }

        // Applicants and employees may only see their own information.
        if ($role !== 'HR') {
            $restricted = $this->restricted($role, $q, $user['name']);

            if ($restricted !== null) {
                return $restricted;
            }
        }

        return match ($role) {
            'HR' => $this->askHr($q),
            'Employee' => $this->askEmployee($q, $user['name']),
            default => $this->askApplicant($q),
        };
    }

    public function help(string $role, string $title, bool $tip = true): array
    {
        $items = match ($role) {
            'HR' => [
                $this->row('"How many applicants today?", "Pending applications", "Who has an interview this week?"', 'low', null, 'Applicants'),
                $this->row('"Status ni kym mendroga", "How many employees do we have?"', 'low', null, 'People'),
                $this->row('"Total worked hours ni Joshua", "Hours of Juan this week"', 'low', null, 'Hours'),
                $this->row('"Who is on duty now?", "Attendance ni Juan"', 'low', null, 'Attendance'),
                $this->row('"Any expired documents?", "Documents ni Juan"', 'low', null, 'Documents'),
                $this->row('"What needs my attention?"', 'low', null, 'Overview'),
            ],
            'Employee' => [
                $this->row('"My worked hours this week", "Total worked hours"', 'low', null, 'Hours'),
                $this->row('"Ilang beses na ako pumasok?"', 'low', null, 'Attendance'),
                $this->row('"Which documents expire soon?"', 'low', null, 'Documents'),
            ],
            default => [
                $this->row('"What is my application status?"', 'low', null, 'Status'),
                $this->row('"What is my next step?"', 'low', null, 'Next step'),
                $this->row('"When is my interview?"', 'low', null, 'Interview'),
            ],
        };

        if ($tip && config('ollama.enabled')) {
            $items[] = $this->row('You can also ask me anything about the data in the system, in English or Filipino.', 'low', null, 'Tip');
        }

        return $this->answer($title, $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Access limits for applicants and employees
    |--------------------------------------------------------------------------
    */

    private function restricted(string $role, string $q, ?string $self): ?array
    {
        $companyWide = $role === 'Applicant'
            ? $this->has($q, ['employee', 'empleyado', 'other applicant', 'ibang applicant', 'all applicants', 'lahat ng applicant'])
            : $this->has($q, [
                'applicant', 'aplikante', 'all employees', 'lahat ng employee', 'other employee',
                'ibang employee', 'how many employees', 'ilang employee', 'ilan ang employee',
                'employee list', 'list of employees',
            ]);

        if (!$companyWide && !$this->mentionsOtherPerson($q, $self)) {
            return null;
        }

        $text = $role === 'Applicant'
            ? 'I can only provide information related to your own application and account.'
            : 'I can only provide information related to your own records.';

        return $this->answer('Access restricted', [
            $this->row($text, 'medium', null, 'Restricted'),
        ]);
    }

    // Is a person other than the user named in the question?
    private function mentionsOtherPerson(string $q, ?string $self): bool
    {
        $people = $this->employeeNames()
            ->merge(Applicant::query()->pluck('name'))
            ->unique()
            ->values();

        $matches = $this->findEmployees($q, $people);

        if ($matches->isEmpty()) {
            return false;
        }

        $mine = $self ? mb_strtolower($self) : null;

        return $matches->contains(fn ($name) => mb_strtolower((string) $name) !== $mine);
    }

    /*
    |--------------------------------------------------------------------------
    | HR
    |--------------------------------------------------------------------------
    */

    private function askHr(string $q): ?array
    {
        if ($this->has($q, self::HOURS_WORDS)) {
            return $this->hrHours($q);
        }

        // "Ilang beses pumasok si Joshua?" with a name, otherwise who is on duty.
        if ($this->has($q, self::ATTENDANCE_WORDS)) {
            $matches = $this->findEmployees($q, $this->employeeNames());

            if ($matches->count() === 1) {
                [$label, $from, $to] = $this->range($q);

                return $this->attendanceFor($matches->first(), $label, $from, $to, "Attendance – {$matches->first()}");
            }

            return $this->onDuty();
        }

        if (
            $this->has($q, ['on duty', 'naka duty', 'nasa duty', 'pumasok', 'who is working', 'clocked in'])
            || $this->hasWord($q, ['present'])
        ) {
            return $this->onDuty();
        }

        // Late, absent, overtime: the system has no shift schedule to compare with.
        if ($this->hasWord($q, self::SCHEDULE_WORDS) || $this->has($q, self::SCHEDULE_PHRASES)) {
            return $this->scheduleQuestion($q);
        }

        if ($this->has($q, self::DOCUMENT_WORDS)) {
            return $this->hrDocuments($q);
        }

        if ($this->has($q, self::EMPLOYEE_WORDS)) {
            return $this->hrEmployees($q);
        }

        // A person's name in the question: answer about that person.
        $person = $this->hrPerson($q);

        if ($person !== null) {
            return $person;
        }

        if ($this->has($q, [
            'applicant', 'application', 'aplikante', 'aplikasyon', 'nag apply', 'nagapply',
            'nag aapply', 'nag submit', 'pending', 'approved', 'rejected', 'interview',
        ])) {
            return $this->hrApplicants($q);
        }

        if ($this->has($q, ['attention', 'overview', 'asikasuhin', 'ano ang bago', 'what s new'])) {
            return $this->hrSummary();
        }

        return $this->isGreeting($q) ? $this->help('HR', 'How can I help?') : null;
    }

    private function hrHours(string $q): array
    {
        [$label, $from, $to] = $this->range($q);

        $names = AttendanceLog::query()
            ->distinct()
            ->orderBy('employee_name')
            ->pluck('employee_name');

        if ($names->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row('There are no attendance records yet.', 'medium'),
            ]);
        }

        $matches = $this->findEmployees($q, $names);

        if ($matches->count() > 1) {
            return $this->answer('Which employee?', [
                $this->row('I found more than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Please write the full name, for example "hours of ' . $matches->first() . '".'),
            ]);
        }

        if ($matches->count() === 1) {
            return $this->hoursFor($matches->first(), $label, $from, $to);
        }

        // A name was typed but nobody matches it.
        $asked = $this->mentionedName($q);

        if ($asked) {
            return $this->answer("I can't find \"{$asked}\"", [
                $this->row('Employees with attendance records: ' . $names->implode(', ') . '.', 'medium'),
                $this->row('Try: "Total worked hours ni ' . Str::before($names->first(), ' ') . '".'),
            ]);
        }

        // No name at all: show everyone.
        $byName = $this->logs(null, $from, $to)
            ->groupBy('employee_name')
            ->map(fn (Collection $group) => $this->minutes($group))
            ->sortDesc();

        if ($byName->isEmpty()) {
            return $this->answer("Worked hours – {$label}", [
                $this->row('No attendance records for ' . Str::lower($label) . '.', 'medium'),
            ]);
        }

        $items = $byName
            ->take(6)
            ->map(fn ($minutes, $name) => $this->row("{$name}: " . $this->fmt((int) $minutes), 'medium', null, 'Hours'))
            ->values()
            ->all();

        $items[] = $this->row('Everyone together: ' . $this->fmt((int) $byName->sum()), 'low', null, 'Total');

        return $this->answer("Worked hours – {$label}", $items);
    }

    private function onDuty(): array
    {
        $onDuty = AttendanceLog::whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->orderBy('time_in')
            ->get();

        $clockedToday = AttendanceLog::whereDate('time_in', today())
            ->distinct()
            ->count('employee_name');

        $items = [];

        if ($onDuty->isEmpty()) {
            $items[] = $this->row('Nobody is clocked in right now.', 'low');
        } else {
            foreach ($onDuty as $log) {
                $items[] = $this->row(
                    "{$log->employee_name}, since " . $log->time_in->format('g:i A'),
                    'medium',
                    null,
                    'On duty'
                );
            }
        }

        $items[] = $this->row("{$clockedToday} employee(s) clocked in today.", 'low');

        return $this->answer('On duty now', $items);
    }

    // "Was Joshua late?": the system only has time in and time out, no schedule.
    private function scheduleQuestion(string $q): array
    {
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() !== 1) {
            return $this->answer('Attendance', [$this->scheduleNote()]);
        }

        $name = $matches->first();

        $result = $this->attendanceFor($name, 'All time', null, null, "Attendance – {$name}");

        array_unshift($result['items'], $this->scheduleNote());

        return $result;
    }

    private function scheduleNote(): array
    {
        return $this->row(
            'The system only records time in and time out. It has no shift schedule, so I cannot tell whether someone was late, absent, or on overtime or undertime.',
            'medium',
            null,
            'Not available'
        );
    }

    // How many shifts, and the last five time in / time out records.
    private function attendanceFor(string $name, string $label, ?Carbon $from, ?Carbon $to, string $title): array
    {
        $logs = $this->logs($name, $from, $to);

        if ($logs->isEmpty()) {
            return $this->answer($title, [
                $this->row('No attendance records for ' . Str::lower($label) . '.', 'medium'),
            ]);
        }

        $finished = $logs->filter(fn ($log) => $log->time_out !== null)->count();
        $when = $from ? Str::lower($label) : 'in total';

        $items = [
            $this->row(
                "{$finished} finished shift(s) {$when}, " . $this->fmt($this->minutes($logs)) . ' worked.',
                'high',
                null,
                'Attendance'
            ),
        ];

        $items = array_merge($items, $this->dutyNotes($logs));

        foreach ($logs->sortByDesc('time_in')->take(5) as $log) {
            $out = $log->time_out ? $log->time_out->format('g:i A') : 'no time out';

            $length = $log->time_out
                ? ' (' . $this->fmt((int) round($log->time_in->diffInMinutes($log->time_out, true))) . ')'
                : '';

            $items[] = $this->row(
                $log->time_in->format('M j') . ': in ' . $log->time_in->format('g:i A') . ', out ' . $out . $length,
                'low',
                null,
                'Record'
            );
        }

        return $this->answer($title, $items);
    }

    // A recent open record means on duty now. An old one is a missing time out.
    private function dutyNotes(Collection $logs): array
    {
        $notes = [];

        $open = $logs
            ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
            ->last();

        if ($open) {
            $notes[] = $this->row(
                'Currently on duty since ' . $open->time_in->format('M j, g:i A') . ' (not counted yet)',
                'medium',
                null,
                'On duty'
            );
        }

        $stale = $logs->filter(fn ($log) => $log->time_out === null && $log->time_in < now()->subHours(24));

        if ($stale->isNotEmpty()) {
            $notes[] = $this->row(
                "{$stale->count()} record(s) have no time out and are not counted, for example the one from "
                    . $stale->first()->time_in->format('M j, g:i A') . '.',
                'medium',
                null,
                'Data note'
            );
        }

        return $notes;
    }

    private function hrDocuments(string $q): array
    {
        $asksCompleteness = $this->has($q, self::COMPLETENESS_WORDS);

        // One person named: show every document of that person.
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() === 1) {
            $name = $matches->first();
            $result = $this->employeeDocuments($name, "Documents – {$name}");

            if ($asksCompleteness) {
                $result['items'][] = $this->completenessNote();
            }

            return $result;
        }

        $total = EmployeeDocument::count();

        if ($total === 0) {
            $items = [
                $this->row('No employee documents are on file yet, so there is nothing to check.', 'medium'),
            ];

            if ($asksCompleteness) {
                $items[] = $this->completenessNote();
            }

            return $this->answer('Document status', $items);
        }

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())
            ->orderBy('expiry_date')
            ->get();

        $soon = EmployeeDocument::whereBetween('expiry_date', [today(), today()->addDays(30)])
            ->orderBy('expiry_date')
            ->get();

        $items = [];

        if ($expired->isNotEmpty()) {
            $items[] = $this->row(
                "{$expired->count()} expired document(s)",
                'critical',
                route('hr.compliance')
            );

            foreach ($expired->take(3) as $document) {
                $days = (int) $document->expiry_date->diffInDays(today(), true);

                $items[] = $this->row(
                    "{$document->employee_name}, {$document->document_type} ({$days} day(s) overdue)",
                    'high',
                    null,
                    'Expired'
                );
            }
        }

        if ($soon->isNotEmpty()) {
            $items[] = $this->row("{$soon->count()} expiring within 30 days", 'high');

            foreach ($soon->take(3) as $document) {
                $days = (int) today()->diffInDays($document->expiry_date, true);

                $items[] = $this->row(
                    "{$document->employee_name}, {$document->document_type} ({$days} day(s) left)",
                    'medium',
                    null,
                    'Expiring'
                );
            }
        }

        if (!$items) {
            $items[] = $this->row("All {$total} document(s) on file are valid.", 'low');
        }

        if ($asksCompleteness) {
            $items[] = $this->completenessNote();
        }

        return $this->answer('Document status', $items);
    }

    private function completenessNote(): array
    {
        return $this->row(
            'The system does not define which documents are required yet, so I can only show what is on file. I cannot tell what is missing.',
            'low',
            null,
            'Note'
        );
    }

    private function employeeNames(): Collection
    {
        return AttendanceLog::query()->distinct()->pluck('employee_name')
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->unique()
            ->sort()
            ->values();
    }

    // "How many employees do we have?" or "Is Joshua Balde an employee?"
    private function hrEmployees(string $q): array
    {
        $names = $this->employeeNames();

        if ($names->isEmpty()) {
            return $this->answer('Employees', [
                $this->row('There are no employees on file yet. A person is listed as an employee once they have attendance records or documents.', 'medium'),
            ]);
        }

        $people = $names->merge(Applicant::query()->pluck('name'))->unique()->sort()->values();
        $matches = $this->findEmployees($q, $people);

        if ($matches->count() === 1) {
            $person = $matches->first();
            $profile = $this->personProfile($person, $this->personFocus($q));

            array_unshift(
                $profile['items'],
                $names->contains($person)
                    ? $this->row('Listed as an employee: attendance records or documents are on file.', 'medium', null, 'Employee')
                    : $this->row('Not listed as an employee: no attendance records or documents are on file.', 'medium', null, 'Employee')
            );

            return $profile;
        }

        $logs = AttendanceLog::whereIn('employee_name', $names)
            ->orderBy('time_in')
            ->get()
            ->groupBy('employee_name');

        $items = [
            $this->row("{$names->count()} employee(s) on file (anyone with attendance records or documents).", 'high'),
        ];

        foreach ($names->take(10) as $name) {
            $own = $logs->get($name, collect());

            $open = $own
                ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
                ->last();

            $items[] = $this->row(
                "{$name}: total " . $this->fmt($this->minutes($own)) . ($open ? ', on duty now' : ''),
                'low',
                null,
                'Employee'
            );
        }

        if ($names->count() > 10) {
            $items[] = $this->row('+' . ($names->count() - 10) . ' more', 'low');
        }

        return $this->answer('Employees', $items);
    }

    // A person (applicant or employee) is named in the question.
    private function hrPerson(string $q): ?array
    {
        $people = $this->employeeNames()
            ->merge(Applicant::query()->pluck('name'))
            ->unique()
            ->sort()
            ->values();

        $matches = $this->findEmployees($q, $people);

        if ($matches->count() === 1) {
            return $this->personProfile($matches->first(), $this->personFocus($q));
        }

        if ($matches->count() > 1) {
            return $this->answer('Which person?', [
                $this->row('More than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Please write the full name, for example "' . $matches->first() . '".', 'low'),
            ]);
        }

        return null;
    }

    // What the question wants to know about the person, so the answer can be direct.
    private function personFocus(string $q): ?string
    {
        if (
            $this->hasWord($q, ['role', 'position'])
            || $this->has($q, ['posisyon', 'job title', 'anong trabaho', 'ano trabaho', 'designation'])
        ) {
            return 'role';
        }

        if ($this->has($q, ['approved', 'approve', 'rejected', 'reject', 'decision', 'decided', 'desisyon', 'tinanggap'])) {
            return 'decision';
        }

        if ($this->has($q, ['interview'])) {
            return 'interview';
        }

        return null;
    }

    private function applicationsOf(string $name): Collection
    {
        return Application::with('applicant')
            ->whereHas('applicant', fn ($query) => $query->where('name', $name))
            ->latest('id')
            ->take(3)
            ->get();
    }

    // The system stores no role or position for employees yet.
    private function personRole(string $name): array
    {
        $items = [
            $this->row('The system does not store a role or position for employees yet.', 'medium', null, 'Not available'),
        ];

        foreach ($this->applicationsOf($name) as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $items[] = $this->row(
                "Applied for {$application->job_title}, status {$status}.",
                'low',
                $application->status === 'draft' ? null : route('hr.applicants.show', $application),
                'Application'
            );
        }

        return $this->answer("Role – {$name}", $items);
    }

    // "When was Joshua approved?"
    private function personDecision(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer("Decision – {$name}", [
                $this->row("No application found for {$name}.", 'medium'),
            ]);
        }

        $items = [];

        foreach ($applications as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $label = $application->job_title . ($application->submitted_at
                ? ' (submitted ' . $application->submitted_at->format('M j, Y g:i A') . ')'
                : '');

            if ($application->reviewed_at && in_array($application->status, ['approved', 'rejected'], true)) {
                $text = "{$label}: {$status} on " . $application->reviewed_at->format('M j, Y g:i A') . '.';
            } else {
                $text = "{$label}: no decision yet, current status {$status}.";
            }

            if ($application->remarks) {
                $text .= ' HR remarks: ' . Str::limit($application->remarks, 120);
            }

            $items[] = $this->row(
                $text,
                $application->status === 'rejected' ? 'high' : 'medium',
                $application->status === 'draft' ? null : route('hr.applicants.show', $application),
                'Decision'
            );
        }

        return $this->answer("Decision – {$name}", $items);
    }

    // "When is Kym's interview?"
    private function personInterview(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer("Interview – {$name}", [
                $this->row("No application found for {$name}.", 'medium'),
            ]);
        }

        $items = [];

        foreach ($applications as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            if ($application->interview_at) {
                $text = "{$application->job_title}: interview on "
                    . Carbon::parse($application->interview_at)->format('l, M j, Y \a\t g:i A')
                    . ($application->interview_location ? ", {$application->interview_location}" : '') . '.';

                if ($application->interview_completed_at) {
                    $text .= ' Completed ' . Carbon::parse($application->interview_completed_at)->format('M j, Y g:i A') . '.';
                }
            } else {
                $text = "{$application->job_title}: no interview scheduled yet, current status {$status}.";
            }

            $items[] = $this->row(
                $text,
                'medium',
                $application->status === 'draft' ? null : route('hr.applicants.show', $application),
                'Interview'
            );
        }

        return $this->answer("Interview – {$name}", $items);
    }

    // Everything the system knows about one person, or just the part that was asked.
    private function personProfile(string $name, ?string $focus = null): array
    {
        if ($focus === 'role') {
            return $this->personRole($name);
        }

        if ($focus === 'decision') {
            return $this->personDecision($name);
        }

        if ($focus === 'interview') {
            return $this->personInterview($name);
        }

        $items = [];

        foreach ($this->applicationsOf($name) as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;
            $parts = ["{$application->job_title}, status {$status}"];

            if ($application->status === 'draft') {
                $parts[] = 'form step ' . (int) $application->step . ' of 3';
            }

            if ($application->submitted_at) {
                $parts[] = 'submitted ' . $application->submitted_at->format('M j, Y g:i A');
            }

            if ($application->interview_at) {
                $parts[] = 'interview ' . Carbon::parse($application->interview_at)->format('M j, Y g:i A')
                    . ($application->interview_location ? ' at ' . $application->interview_location : '');
            }

            if ($application->reviewed_at) {
                $parts[] = 'decided ' . $application->reviewed_at->format('M j, Y g:i A');
            }

            if ($application->remarks) {
                $parts[] = 'HR remarks: ' . Str::limit($application->remarks, 80);
            }

            $items[] = $this->row(
                implode('; ', $parts) . '.',
                'low',
                $application->status === 'draft' ? null : route('hr.applicants.show', $application),
                'Application'
            );
        }

        $logs = AttendanceLog::where('employee_name', $name)->orderBy('time_in')->get();

        if ($logs->isNotEmpty()) {
            $week = $this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek()));
            $month = $this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth()));

            $open = $logs
                ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
                ->last();

            $items[] = $this->row(
                'Worked hours: this week ' . $this->fmt($week)
                    . '; this month ' . $this->fmt($month)
                    . '; all time ' . $this->fmt($this->minutes($logs))
                    . ($open ? '; on duty now since ' . $open->time_in->format('g:i A') : '; not on duty now') . '.',
                $open ? 'medium' : 'low',
                null,
                'Attendance'
            );
        }

        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $state = "expired {$days} day(s) ago";
                $level = 'critical';
            } elseif ($document->expiry_date->lte(today()->addDays(30))) {
                $state = "{$days} day(s) left";
                $level = 'high';
            } else {
                $state = 'valid';
                $level = 'low';
            }

            $items[] = $this->row(
                "{$document->document_type}, expires " . $document->expiry_date->format('M j, Y') . " ({$state}).",
                $level,
                null,
                'Document'
            );
        }

        if (!$items) {
            $items[] = $this->row("No records found for {$name}.", 'medium');
        }

        return $this->answer($name, $items);
    }

    private function hrApplicants(string $q): array
    {
        [$label, $from, $to] = $this->range($q);

        // Applicants who have not submitted yet.
        if ($this->has($q, ['in progress', 'draft', 'filling', 'incomplete'])) {
            $count = Application::where('status', 'draft')->count();

            return $this->answer('Applications in progress', [
                $this->row(
                    "{$count} applicant(s) are still filling out their application.",
                    $count > 0 ? 'medium' : 'low'
                ),
            ]);
        }

        $statuses = null;
        $what = 'submitted';

        if ($this->has($q, ['approved', 'approve'])) {
            $statuses = ['approved'];
            $what = 'approved';
        } elseif ($this->has($q, ['rejected', 'reject', 'denied'])) {
            $statuses = ['rejected'];
            $what = 'rejected';
        } elseif ($this->has($q, ['interview'])) {
            $statuses = ['interview_scheduled', 'interview_completed'];
            $what = 'in the interview stage';
        } elseif ($this->has($q, ['pending', 'review', 'waiting', 'hintay', 'naghihintay'])) {
            $statuses = ['submitted', 'under_review'];
            $what = 'waiting for review';
        }

        // Interview questions use the interview date, everything else the submission date.
        $interviewStage = $statuses === ['interview_scheduled', 'interview_completed'];
        $dateColumn = $interviewStage ? 'interview_at' : 'submitted_at';

        $applications = Application::with('applicant')
            ->where('status', '!=', 'draft')
            ->when($from, fn ($query) => $query->where($dateColumn, '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($query) => $query->where($dateColumn, '<=', $to->copy()->endOfDay()))
            ->when($statuses, fn ($query) => $query->whereIn('status', $statuses))
            ->orderBy($dateColumn, $interviewStage ? 'asc' : 'desc')
            ->get();

        $count = $applications->count();
        $when = $from ? Str::lower($label) : 'in total';

        if ($interviewStage && $from) {
            $when .= ' (by interview date)';
        }

        $isPending = $statuses === ['submitted', 'under_review'];

        $items = [
            $this->row(
                "{$count} application(s) {$what} – {$when}",
                $count === 0 ? 'low' : ($isPending ? 'high' : 'medium'),
                $count > 0 ? route('hr.applicants') : null
            ),
        ];

        // Breakdown by status, only when the question was not about one status.
        if ($statuses === null && $count > 0) {
            $parts = $applications
                ->groupBy('status')
                ->map(fn (Collection $group, string $status) => (self::STATUS_LABELS[$status] ?? $status) . ': ' . $group->count())
                ->values()
                ->implode(' · ');

            $items[] = $this->row($parts, 'low', null, 'By status');
        }

        foreach ($applications->take(5) as $application) {
            $name = $application->applicant->name ?? 'Unknown';
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $line = "{$name}, {$application->job_title} ({$status})";

            if ($interviewStage && $application->interview_at) {
                $line .= ', interview ' . Carbon::parse($application->interview_at)->format('M j, g:i A')
                    . ($application->interview_location ? ' at ' . $application->interview_location : '');
            }

            $items[] = $this->row($line, 'low', route('hr.applicants.show', $application), 'Applicant');
        }

        if ($count > 5) {
            $items[] = $this->row('+' . ($count - 5) . ' more', 'low');
        }

        return $this->answer("Applicants – {$label}", $items);
    }

    // "What needs my attention?": everything HR still has to act on.
    private function hrSummary(): array
    {
        $counts = Application::query()
            ->whereIn('status', ['submitted', 'under_review', 'interview_scheduled', 'interview_completed'])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $new = (int) ($counts['submitted'] ?? 0);
        $underReview = (int) ($counts['under_review'] ?? 0);
        $scheduled = (int) ($counts['interview_scheduled'] ?? 0);
        $interviewed = (int) ($counts['interview_completed'] ?? 0);

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())->count();

        $soon = EmployeeDocument::whereBetween('expiry_date', [today(), today()->addDays(30)])->count();

        $onDuty = AttendanceLog::whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->count();

        $items = [];

        if ($interviewed > 0) {
            $items[] = $this->row("{$interviewed} interview(s) done, ready for your decision", 'high', route('hr.applicants'));
        }

        if ($new > 0) {
            $items[] = $this->row("{$new} new application(s) to review", 'high', route('hr.applicants'));
        }

        if ($underReview > 0) {
            $items[] = $this->row("{$underReview} under review, interview still to be scheduled", 'medium', route('hr.applicants'));
        }

        if ($scheduled > 0) {
            $items[] = $this->row("{$scheduled} interview(s) scheduled", 'medium', route('hr.applicants'));
        }

        if ($expired > 0) {
            $items[] = $this->row("{$expired} expired employee document(s)", 'critical', route('hr.compliance'));
        }

        if ($soon > 0) {
            $items[] = $this->row("{$soon} document(s) expiring within 30 days", 'high', route('hr.compliance'));
        }

        if (!$items) {
            $items[] = $this->row('Nothing needs your attention right now.', 'low');
        }

        $items[] = $this->row("{$onDuty} employee(s) on duty now.", 'low');

        return $this->answer('What needs your attention', $items);
    }

    private function identity(string $role, ?string $name): array
    {
        if ($role === 'Applicant' && !$name) {
            return $this->answer('Who you are', [
                $this->row('Log in and I can tell you which account you are using.', 'medium'),
            ]);
        }

        if ($name) {
            $kind = $role === 'Employee' ? 'an employee' : 'a job applicant';

            return $this->answer('Who you are', [
                $this->row("You are {$name}, {$kind} at Frontier Security Services Inc.", 'low'),
            ]);
        }

        return $this->answer('Who you are', [
            $this->row('You are using the HR portal. I do not know your name because HR has no login in this demo yet.', 'low'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Employee
    |--------------------------------------------------------------------------
    */

    private function askEmployee(string $q, string $name): ?array
    {
        if ($this->has($q, self::HOURS_WORDS)) {
            [$label, $from, $to] = $this->range($q);

            return $this->hoursFor($name, $label, $from, $to, 'My worked hours');
        }

        if ($this->has($q, self::ATTENDANCE_WORDS)) {
            [$label, $from, $to] = $this->range($q);

            return $this->attendanceFor($name, $label, $from, $to, 'My attendance');
        }

        if ($this->hasWord($q, self::SCHEDULE_WORDS) || $this->has($q, self::SCHEDULE_PHRASES)) {
            $result = $this->attendanceFor($name, 'All time', null, null, 'My attendance');

            array_unshift($result['items'], $this->scheduleNote());

            return $result;
        }

        if ($this->has($q, self::DOCUMENT_WORDS)) {
            return $this->employeeDocuments($name);
        }

        return $this->isGreeting($q) ? $this->help('Employee', 'How can I help?') : null;
    }

    private function employeeDocuments(string $name, string $title = 'My documents'): array
    {
        $documents = EmployeeDocument::where('employee_name', $name)
            ->orderBy('expiry_date')
            ->get();

        if ($documents->isEmpty()) {
            return $this->answer($title, [
                $this->row('No documents are on file yet.', 'medium'),
            ]);
        }

        $items = [];

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row("{$document->document_type}, expired {$days} day(s) ago", 'critical', null, 'Expired');
            } elseif ($document->expiry_date->lte(today()->addDays(30))) {
                $items[] = $this->row("{$document->document_type}, {$days} day(s) left", 'high', null, 'Expiring');
            } else {
                $items[] = $this->row("{$document->document_type}, valid until " . $document->expiry_date->format('M j, Y'), 'low', null, 'Valid');
            }
        }

        return $this->answer($title, $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Applicant (always the logged-in applicant, never an id from the request)
    |--------------------------------------------------------------------------
    */

    private function askApplicant(string $q): ?array
    {
        $intent = match (true) {
            $this->has($q, ['interview', 'schedule', 'iskedyul']) => 'interview',
            $this->has($q, ['next', 'susunod', 'what do i do', 'gagawin']) => 'next',
            $this->has($q, ['status', 'application', 'where is my', 'nasaan', 'any update', 'result']) => 'status',
            default => null,
        };

        if ($intent === null) {
            return $this->isGreeting($q) ? $this->help('Applicant', 'How can I help?') : null;
        }

        $applicant = Auth::guard('applicant')->user();

        if (!$applicant) {
            return $this->answer('Please log in', [
                $this->row('Log in to ask about your application.', 'medium'),
            ]);
        }

        $application = $applicant->applications()->latest('id')->first();

        if (!$application) {
            return $this->answer('No application yet', [
                $this->row('You have not started an application yet.', 'medium'),
                $this->row('Click "Start Application" on your dashboard to begin.', 'medium'),
            ]);
        }

        $label = self::STATUS_LABELS[$application->status] ?? ucfirst($application->status);

        if ($intent === 'interview') {
            if ($application->interview_at) {
                $when = Carbon::parse($application->interview_at);

                $items = [
                    $this->row('Your interview: ' . $when->format('l, M j, Y \a\t g:i A'), 'high'),
                ];

                if ($application->interview_location) {
                    $items[] = $this->row("Location: {$application->interview_location}", 'medium');
                }

                return $this->answer('Your interview', $items);
            }

            return $this->answer('Your interview', [
                $this->row('No interview is scheduled yet.', 'medium'),
                $this->row($this->nextStep($application), 'low', null, 'Next step'),
            ]);
        }

        if ($intent === 'next') {
            return $this->answer('Your next step', [
                $this->row($this->nextStep($application), 'high'),
            ]);
        }

        $level = in_array($application->status, ['approved', 'rejected'], true) ? 'high' : 'medium';

        $items = [
            $this->row("{$application->job_title}: {$label}", $level, null, 'Status'),
        ];

        if ($application->remarks) {
            $items[] = $this->row($application->remarks, 'medium', null, 'HR remarks');
        }

        $items[] = $this->row($this->nextStep($application), 'low', null, 'Next step');

        return $this->answer('Your application', $items);
    }

    private function nextStep(Application $application): string
    {
        return match ($application->status) {
            'draft' => match ((int) $application->step) {
                1 => 'Fill in your phone number and address, then click Continue.',
                2 => 'Tick the 4 required documents you have ready, then click Continue.',
                3 => 'Review your details and click Submit Application.',
                default => 'Continue your application on your dashboard.',
            },
            'submitted' => 'Wait for HR to start reviewing your application.',
            'under_review' => 'Wait for HR to set your interview schedule.',
            'interview_scheduled' => 'Attend your interview on time.',
            'interview_completed' => 'Wait for HR to make the final decision.',
            'approved' => 'Congratulations. Watch your dashboard for updates from HR.',
            'rejected' => 'Read the HR remarks on your dashboard.',
            default => 'Check your dashboard for updates.',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Worked hours
    |--------------------------------------------------------------------------
    */

    private function hoursFor(
        string $name,
        string $label,
        ?Carbon $from,
        ?Carbon $to,
        ?string $title = null
    ): array {
        $title ??= "{$name} – worked hours";

        $logs = $this->logs($name, $from, $to);
        $closed = $logs->filter(fn ($log) => $log->time_out !== null);

        $items = [];

        if ($logs->isEmpty()) {
            $items[] = $this->row('No attendance records for ' . Str::lower($label) . '.', 'medium');
        } else {
            $when = $from ? $label : 'Total (all time)';

            $items[] = $this->row(
                "{$when}: " . $this->fmt($this->minutes($closed)) . " in {$closed->count()} shift(s)",
                'high'
            );
        }

        if ($from === null) {
            $month = $this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth()));
            $week = $this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek()));

            $items[] = $this->row('This month: ' . $this->fmt($month), 'low');
            $items[] = $this->row('This week: ' . $this->fmt($week), 'low');
        } else {
            $all = $this->minutes($this->logs($name, null, null));

            $items[] = $this->row('Total (all time): ' . $this->fmt($all), 'low');
        }

        return $this->answer($title, array_merge($items, $this->dutyNotes($logs)));
    }

    private function logs(?string $name, ?Carbon $from, ?Carbon $to): Collection
    {
        return AttendanceLog::query()
            ->when($name, fn ($query) => $query->where('employee_name', $name))
            ->when($from, fn ($query) => $query->where('time_in', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($query) => $query->where('time_in', '<=', $to->copy()->endOfDay()))
            ->orderBy('time_in')
            ->get();
    }

    // Only finished shifts count. An overnight shift works because
    // time_in and time_out are full date-times.
    private function minutes(Collection $logs): int
    {
        return (int) round(
            $logs
                ->filter(fn ($log) => $log->time_out !== null)
                ->sum(fn ($log) => $log->time_in->diffInMinutes($log->time_out, true))
        );
    }

    private function fmt(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? "{$hours} h {$rest} min" : "{$hours} h";
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the question
    |--------------------------------------------------------------------------
    */

    // Lowercase, no punctuation: "Nag-apply today?" becomes "nag apply today"
    private function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function has(string $q, array $needles): bool
    {
        return Str::contains($q, $needles);
    }

    private function hasWord(string $q, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $q)) {
                return true;
            }
        }

        return false;
    }

    private function isGreeting(string $q): bool
    {
        return $this->hasWord($q, self::GREETING_WORDS)
            || $this->has($q, ['what can you do', 'who are you', 'ano kaya mo', 'ano ang kaya mo']);
    }

    // Returns [label, from, to]. Nulls mean "all time".
    private function range(string $q): array
    {
        if (preg_match('/(?:last|past|nakaraang)\s+(\d{1,3})\s+(?:days?|araw)/u', $q, $match)) {
            $days = max(1, min(365, (int) $match[1]));

            return ["Last {$days} days", today()->subDays($days - 1), today()];
        }

        // "last week" must be checked before the plain word "week".
        if ($this->has($q, ['last week', 'previous week', 'nakaraang linggo', 'noong isang linggo'])) {
            $start = today()->subWeek()->startOfWeek();

            return ['Last week', $start, $start->copy()->endOfWeek()];
        }

        if ($this->has($q, ['last month', 'previous month', 'nakaraang buwan', 'noong isang buwan'])) {
            $start = today()->subMonthNoOverflow()->startOfMonth();

            return ['Last month', $start, $start->copy()->endOfMonth()];
        }

        if ($this->has($q, ['yesterday', 'kahapon'])) {
            return ['Yesterday', today()->subDay(), today()->subDay()];
        }

        if ($this->has($q, ['tomorrow', 'bukas'])) {
            return ['Tomorrow', today()->addDay(), today()->addDay()];
        }

        if ($this->has($q, ['today', 'ngayong araw', 'ngayon'])) {
            return ['Today', today(), today()];
        }

        if ($this->has($q, ['week', 'linggo'])) {
            return ['This week', today()->startOfWeek(), today()->endOfWeek()];
        }

        if ($this->has($q, ['month', 'buwan'])) {
            return ['This month', today()->startOfMonth(), today()->endOfMonth()];
        }

        return ['All time', null, null];
    }

    // Matches a first, middle or last name: "ni joshua" finds "Joshua Balde".
    // When several names share a part ("kym mendroga" and "mendroga"), the one
    // whose full name is written in the question wins, longest first.
    private function findEmployees(string $q, Collection $names): Collection
    {
        $words = collect(explode(' ', $q));

        $matches = $names
            ->filter(function ($name) use ($words) {
                $tokens = collect(explode(' ', $this->normalize((string) $name)))
                    ->filter(fn ($token) => mb_strlen($token) >= 3)
                    ->reject(fn ($token) => in_array($token, self::NAME_STOP, true));

                return $tokens->intersect($words)->isNotEmpty();
            })
            ->values();

        if ($matches->count() < 2) {
            return $matches;
        }

        $padded = " {$q} ";

        $written = $matches->filter(
            fn ($name) => str_contains($padded, ' ' . $this->normalize((string) $name) . ' ')
        );

        if ($written->isEmpty()) {
            return $matches;
        }

        $longest = $written->max(fn ($name) => mb_strlen($this->normalize((string) $name)));

        return $written
            ->filter(fn ($name) => mb_strlen($this->normalize((string) $name)) === $longest)
            ->values();
    }

    // The word after "ni", "kay", "of" or "for", if it looks like a name
    private function mentionedName(string $q): ?string
    {
        if (!preg_match('/\b(?:ni|kay|of|for)\s+(\p{L}+)/u', $q, $match)) {
            return null;
        }

        $skip = [
            'the', 'all', 'everyone', 'everybody', 'lahat', 'my', 'me', 'this', 'last',
            'today', 'yesterday', 'week', 'month', 'total', 'our', 'each', 'every',
            'mga', 'employee', 'employees',
        ];

        return in_array($match[1], $skip, true) ? null : $match[1];
    }

    /*
    |--------------------------------------------------------------------------
    | Answer shape: a title and rows. "tag" is a small label above a row.
    |--------------------------------------------------------------------------
    */

    private function row(string $text, string $level = 'low', ?string $url = null, ?string $tag = null): array
    {
        return compact('text', 'level', 'url', 'tag');
    }

    private function answer(string $title, array $items): array
    {
        return [
            'title' => $title,
            'items' => array_values($items),
        ];
    }
}