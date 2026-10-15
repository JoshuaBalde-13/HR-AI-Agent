<?php

namespace App\Services\AI;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Exact, deterministic answers. Every number comes straight from the database
 * and no AI is involved.
 *
 * Every answer has the same shape:
 *   - the first row is the direct answer (one sentence),
 *   - the other rows are short bullets.
 */
class RuleAnswers
{
    // Bump this when the file changes. "php artisan ai:check" reads it.
    public const VERSION = 6;

    private const STATUS_LABELS = [
        'draft' => 'In progress',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'interview_scheduled' => 'Interview scheduled',
        'interview_completed' => 'Interview completed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // Checked first: "hindi natanggap" also contains "natanggap".
    private const REJECTED_WORDS = [
        'rejected', 'reject', 'denied', 'hindi natanggap', 'hindi tinanggap', 'hindi pumasa', 'bagsak',
    ];

    private const APPROVED_WORDS = [
        'approved', 'approve', 'tinanggap', 'natanggap', 'nakapasa', 'pumasa',
    ];

    // Specific on purpose, so a general chat question is not mistaken for data.
    private const HOURS_WORDS = [
        'worked', 'work hour', 'working hour', 'duty hour', 'total hour', 'how many hours',
        'hours of', 'hours ni', 'hours this', 'hours today', 'hours yesterday', 'my hours',
        'ilang oras', 'oras ni', 'oras ng', 'oras ang',
        'pinakamaraming oras', 'pinakakaunting oras', 'most hours', 'least hours',
        'fewest hours', 'highest hours',
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
        'document', 'dokumento', 'papeles', 'papel', 'expire', 'expiry', 'expired',
        'license', 'nbi', 'medical', 'renew',
    ];

    // Matched as whole words, so "interview completed" is not a completeness question.
    private const COMPLETENESS_WORDS = [
        'kumpleto', 'complete', 'incomplete', 'missing', 'kulang', 'requirement', 'requirements',
    ];

    private const EMPLOYEE_WORDS = ['employee', 'empleyado', 'staff', 'personnel'];

    private const GREETING_WORDS = ['help', 'hi', 'hello', 'hey', 'kumusta', 'tulong'];

    private const THANKS_WORDS = ['thank you', 'thanks', 'thank u', 'salamat', 'tnx'];

    private const BYE_WORDS = ['bye', 'goodbye', 'paalam', 'goodnight'];

    private const ACK_WORDS = [
        'ok', 'okay', 'sige', 'noted', 'got', 'it', 'ayos', 'nice', 'great',
        'cool', 'alright', 'opo', 'po',
    ];

    private const HOWTO_WORDS = ['paano', 'how to', 'how do i', 'how can i', 'steps to'];

    private const PRONOUNS = [
        'siya', 'siyang', 'niya', 'niyang', 'nya', 'kanya', 'kanyang', 'he', 'she', 'his', 'her', 'him',
    ];

    private const PERIOD_REGEX = '/\b(today|yesterday|tomorrow|this week|this month|last week|last month|ngayong araw|ngayong linggo|ngayong buwan|nakaraang linggo|nakaraang buwan|noong isang linggo|noong isang buwan|ngayon|kahapon|bukas)\b/u';

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
        // Preserve context for short follow-up questions such as "last week?".
        $q = $this->followUp($q, $user['previous'] ?? null);

        $small = $this->smallTalk($q);
        if ($small !== null) {
            $small['asked'] = $q;
            return $small;
        }

        $guide = $this->howTo($role, $q);
        if ($guide !== null) {
            $guide['asked'] = $q;
            return $guide;
        }

        if ($this->has($q, ['my name', 'who am i', 'sino ako', 'pangalan ko'])) {
            $answer = $this->identity($role, $user['name'] ?? null);
            $answer['asked'] = $q;
            return $answer;
        }

        // Applicants and employees may only see their own information.
        if (!in_array($role, ['HR', 'HR Personnel'], true)) {
            $restricted = $this->restricted($role, $q, $user['name'] ?? null);
            if ($restricted !== null) {
                $restricted['asked'] = $q;
                return $restricted;
            }
        }

        $answer = match ($role) {
            'HR', 'HR Personnel' => $this->askHr($this->withPerson($q, $user['person'] ?? null)),
            'Employee' => $this->askEmployee($q, $user['name'] ?? ''),
            default => $this->askApplicant($q),
        };

        if ($answer !== null) {
            $answer['asked'] = $q;
        }

        return $answer;
    }

    // A short follow-up containing only a time period reuses the previous question.
    private function followUp(string $q, ?string $previous): string
    {
        if (!$previous || !preg_match(self::PERIOD_REGEX, $q)) {
            return $q;
        }

        $rest = trim(preg_replace(self::PERIOD_REGEX, '', $q));
        $rest = trim(preg_replace('/\b(naman|din|rin|pa|at|po|ba)\b/u', '', $rest));

        if ($rest !== '') {
            return $q;
        }

        return trim(preg_replace(self::PERIOD_REGEX, '', $previous) . ' ' . $q);
    }

    // Resolves pronouns such as "siya" to the last person discussed in the chat.
    private function withPerson(string $q, ?string $last): string
    {
        if (!$last || !$this->hasWord($q, self::PRONOUNS)) {
            return $q;
        }

        $people = $this->employeeNames()->merge(Applicant::query()->pluck('name'))->unique()->values();
        if ($this->findEmployees($q, $people)->isNotEmpty()) {
            return $q;
        }

        return $q . ' ' . $this->normalize($last);
    }

    // $lead is the first line: what went wrong, or what to try.
    public function help(string $role, string $lead, bool $tip = true): array
    {
        $items = [$this->row($lead, 'medium')];

        $examples = match ($role) {
            'HR' => [
                ['Applicants', '"How many applicants today?", "Pending applications", "Who has an interview this week?"'],
                ['People', '"Status ni kym mendroga", "Anong position ni Joshua?", "How many active employees?"'],
                ['Hours', '"Total worked hours ni Joshua", "Sino ang pinakamaraming oras?"'],
                ['Attendance', '"Who is on duty now?", "Ilang beses pumasok si Joshua?"'],
                ['Documents', '"Any expired documents?", "Kumpleto ba documents ni Joshua?", "Which employees have incomplete documents?"'],
                ['Overview', '"What needs my attention?"'],
            ],
            'Employee' => [
                ['Hours', '"My worked hours this week", "Ilang beses na ako pumasok?"'],
                ['Documents', '"Which documents expire soon?", "Kumpleto ba documents ko?"'],
                ['Record', '"Anong position ko?"'],
            ],
            default => [
                ['Status', '"What is my application status?"'],
                ['Next step', '"What is my next step?"'],
                ['Interview', '"When is my interview?"'],
                ['Help', '"Paano mag-apply?"'],
            ],
        };

        foreach ($examples as [$tag, $text]) {
            $items[] = $this->row($text, 'low', null, $tag);
        }

        if ($tip && config('ollama.enabled')) {
            $items[] = $this->row('You can also type the question in your own words, in English or Filipino.', 'low', null, 'Tip');
        }

        return $this->answer('', $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Small talk and step-by-step help
    |--------------------------------------------------------------------------
    */

    private function smallTalk(string $q): ?array
    {
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);

        // Only short messages: a long question that also says thanks is a real question.
        if (!$words || count($words) > 4) {
            return null;
        }

        $filipino = $this->hasWord($q, ['salamat', 'sige', 'paalam', 'po', 'opo', 'ayos']);

        if ($this->has($q, self::THANKS_WORDS)) {
            return $this->answer('', [
                $this->row($filipino
                    ? 'Walang anuman. Sabihin mo lang kung may kailangan ka pa.'
                    : 'You are welcome. Let me know if you need anything else.'),
            ]);
        }

        if ($this->hasWord($q, self::BYE_WORDS)) {
            return $this->answer('', [
                $this->row($filipino
                    ? 'Paalam. Nandito lang ako kung kailangan mo.'
                    : 'Goodbye. I am here whenever you need me.'),
            ]);
        }

        $acknowledged = collect($words)->every(fn ($word) => in_array($word, self::ACK_WORDS, true));

        if ($acknowledged) {
            return $this->answer('', [
                $this->row($filipino
                    ? 'Sige. Sabihin mo lang kung may kailangan ka pa.'
                    : 'Noted. Let me know if you need anything else.'),
            ]);
        }

        return null;
    }

    // "How do I ...?" gets fixed steps for the topics each role can use.
    private function howTo(string $role, string $q): ?array
    {
        if (!$this->has($q, self::HOWTO_WORDS)) {
            return null;
        }

        if ($role === 'Applicant' && $this->has($q, ['apply', 'application', 'aplay'])) {
            return $this->answer('How to apply', [
                $this->row('To apply for Security Guard:', 'medium'),
                $this->row('1. Log in to your applicant account and click Start Application.'),
                $this->row('2. Step 1: enter your phone number and address, then click Continue.'),
                $this->row('3. Step 2: tick the 4 required documents (Government ID, NBI Clearance, Medical Certificate, Security Training Certificate), then click Continue.'),
                $this->row('4. Step 3: review your details and click Submit Application.'),
            ]);
        }

        if ($role === 'Employee' && $this->has($q, ['renew', 'document', 'expire'])) {
            return $this->answer('How to renew a document', [
                $this->row('To renew a document:', 'medium'),
                $this->row('1. Open My Documents.'),
                $this->row('2. Pick a new expiry date that is later than the current one.'),
                $this->row('3. Click Renew. HR is notified.'),
            ]);
        }

        if ($role === 'HR' && $this->has($q, ['compliance', 'expired', 'expiring', 'document'])) {
            return $this->answer('How to check compliance', [
                $this->row('To check document compliance:', 'medium'),
                $this->row('1. Open Compliance in the side menu.'),
                $this->row('2. Expired documents are listed first, then those expiring within 30 days.'),
            ]);
        }

        if ($role === 'HR' && $this->has($q, ['review', 'approve', 'reject', 'applicant', 'application'])) {
            return $this->answer('How to review an applicant', [
                $this->row('To review an applicant:', 'medium'),
                $this->row('1. Open Applicants in the side menu.'),
                $this->row('2. Use the tabs (Pending, Approved, Rejected, All) and click Review on an application.'),
                $this->row('3. Start the review, then Approve or Reject. A reason is required when rejecting.'),
            ]);
        }

        return null;
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

        return $this->answer('', [$this->row($text, 'medium')]);
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

                return $this->attendanceFor($matches->first(), $label, $from, $to);
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

        if ($this->asksCompleteness($q) && $this->has($q, self::EMPLOYEE_WORDS)) {
            return $this->incompleteEmployees();
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
            'nag aapply', 'nag submit', 'pending', 'interview',
        ]) || $this->has($q, self::APPROVED_WORDS) || $this->has($q, self::REJECTED_WORDS)) {
            return $this->hrApplicants($q);
        }

        if ($this->has($q, ['attention', 'overview', 'asikasuhin', 'ano ang bago', 'what s new'])) {
            return $this->hrSummary();
        }

        return $this->isGreeting($q) ? $this->help('HR', 'How can I help?') : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Worked hours and attendance
    |--------------------------------------------------------------------------
    */

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
            return $this->answer('Worked hours', [
                $this->row('More than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Write the full name, for example "hours of ' . $matches->first() . '".'),
            ]);
        }

        if ($matches->count() === 1) {
            return $this->hoursFor($matches->first(), $label, $from, $to);
        }

        // A name was typed but nobody matches it.
        $asked = $this->mentionedName($q);

        if ($asked) {
            return $this->answer('Worked hours', [
                $this->row("I can't find \"{$asked}\" among the employees with attendance records.", 'medium'),
                $this->row('Employees with attendance: ' . $names->implode(', ') . '.'),
            ]);
        }

        // No name at all: everyone.
        $byName = $this->logs(null, $from, $to)
            ->groupBy('employee_name')
            ->map(fn (Collection $group) => $this->minutes($group))
            ->sortDesc();

        if ($byName->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row('No attendance records ' . ($from ? 'for ' . Str::lower($label) : 'yet') . '.', 'medium'),
            ]);
        }

        $when = $this->when($label, $from);
        $ranking = $this->rankingWanted($q);

        if ($ranking !== null && $byName->first() > 0) {
            $top = $ranking === 'most' ? $byName->keys()->first() : $byName->keys()->last();
            $minutes = $ranking === 'most' ? $byName->first() : $byName->last();

            $lead = ($ranking === 'most' ? 'Most' : 'Fewest') . " worked hours {$when}: {$top} (" . $this->fmt((int) $minutes) . ').';
        } else {
            $lead = 'Everyone together worked ' . $this->fmt((int) $byName->sum()) . " {$when}.";
        }

        $items = [$this->row($lead, 'high')];

        foreach ($byName->take(6) as $name => $minutes) {
            $items[] = $this->row("{$name}: " . $this->fmt((int) $minutes));
        }

        return $this->answer('Worked hours', $items);
    }

    private function rankingWanted(string $q): ?string
    {
        if ($this->has($q, ['pinakakaunti', 'pinakakaunting', 'least', 'fewest', 'lowest'])) {
            return 'least';
        }

        if (
            $this->has($q, ['pinakamaraming', 'pinakamarami', 'most hours', 'highest'])
            || $this->hasWord($q, ['most', 'top'])
        ) {
            return 'most';
        }

        return null;
    }

    private function hoursFor(string $name, string $label, ?Carbon $from, ?Carbon $to, bool $self = false): array
    {
        $subject = $self ? 'You' : $name;
        $logs = $this->logs($name, $from, $to);

        if ($logs->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row("No attendance records found for {$subject}" . ($from ? ' ' . Str::lower($label) : '') . '.', 'medium'),
            ]);
        }

        $closed = $logs->filter(fn ($log) => $log->time_out !== null);

        $items = [
            $this->row(
                "{$subject} worked " . $this->fmt($this->minutes($closed)) . ' ' . $this->when($label, $from)
                    . " ({$closed->count()} shift(s)).",
                'high'
            ),
        ];

        if ($from === null) {
            $items[] = $this->row('This month: ' . $this->fmt($this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth()))));
            $items[] = $this->row('This week: ' . $this->fmt($this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek()))));
        } else {
            $items[] = $this->row('In total: ' . $this->fmt($this->minutes($this->logs($name, null, null))));
        }

        return $this->answer('Worked hours', array_merge($items, $this->dutyNotes($logs)));
    }

    // How many shifts, and the last five time in / time out records.
    private function attendanceFor(string $name, string $label, ?Carbon $from, ?Carbon $to, bool $self = false): array
    {
        $subject = $self ? 'You' : $name;
        $logs = $this->logs($name, $from, $to);

        if ($logs->isEmpty()) {
            return $this->answer('Attendance', [
                $this->row("No attendance records found for {$subject}" . ($from ? ' ' . Str::lower($label) : '') . '.', 'medium'),
            ]);
        }

        $finished = $logs->filter(fn ($log) => $log->time_out !== null)->count();

        $items = [
            $this->row(
                $subject . ($self ? ' have ' : ' has ') . "{$finished} finished shift(s) " . $this->when($label, $from)
                    . ', ' . $this->fmt($this->minutes($logs)) . ' worked.',
                'high'
            ),
        ];

        $items = array_merge($items, $this->dutyNotes($logs));

        foreach ($logs->sortByDesc('time_in')->take(5) as $log) {
            $out = $log->time_out ? $log->time_out->format('g:i A') : 'no time out';

            $length = $log->time_out
                ? ' (' . $this->fmt((int) round($log->time_in->diffInMinutes($log->time_out, true))) . ')'
                : '';

            $items[] = $this->row($log->time_in->format('M j') . ': in ' . $log->time_in->format('g:i A') . ', out ' . $out . $length);
        }

        return $this->answer('Attendance', $items);
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
                'On duty now since ' . $open->time_in->format('M j, g:i A') . ' (not counted yet).',
                'medium'
            );
        }

        $stale = $logs->filter(fn ($log) => $log->time_out === null && $log->time_in < now()->subHours(24));

        if ($stale->isNotEmpty()) {
            $notes[] = $this->row(
                "Data note: {$stale->count()} record(s) have no time out and are not counted, for example the one from "
                    . $stale->first()->time_in->format('M j, g:i A') . '.',
                'medium'
            );
        }

        return $notes;
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

        if ($onDuty->isEmpty()) {
            return $this->answer('On duty', [
                $this->row('Nobody is on duty right now.', 'low'),
                $this->row("{$clockedToday} employee(s) clocked in today."),
            ]);
        }

        $items = [$this->row("{$onDuty->count()} on duty now.", 'high')];

        foreach ($onDuty as $log) {
            $items[] = $this->row("{$log->employee_name}, since " . $log->time_in->format('g:i A'), 'medium');
        }

        $items[] = $this->row("{$clockedToday} employee(s) clocked in today.");

        return $this->answer('On duty', $items);
    }

    // "Was Joshua late?": the system only has time in and time out, no schedule.
    private function scheduleQuestion(string $q): array
    {
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() !== 1) {
            return $this->answer('Attendance', $this->scheduleNotes());
        }

        $records = $this->attendanceFor($matches->first(), 'All time', null, null);

        return $this->answer('Attendance', array_merge($this->scheduleNotes(), $records['items']));
    }

    private function scheduleNotes(): array
    {
        return [
            $this->row('The system has no shift schedule, so it cannot tell who is late, absent, or on overtime or undertime.', 'medium'),
            $this->row('It only records time in and time out.'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Documents and completeness
    |--------------------------------------------------------------------------
    */

    private function asksCompleteness(string $q): bool
    {
        return $this->hasWord($q, self::COMPLETENESS_WORDS);
    }

    private function hrDocuments(string $q): array
    {
        $asksCompleteness = $this->asksCompleteness($q);

        // One person named: that person only.
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() === 1) {
            $name = $matches->first();

            return $asksCompleteness
                ? $this->personCompleteness($name)
                : $this->employeeDocuments($name);
        }

        // "Which employees have incomplete documents?"
        if ($asksCompleteness) {
            return $this->incompleteEmployees();
        }

        $total = EmployeeDocument::count();

        if ($total === 0) {
            return $this->answer('Documents', [
                $this->row('No employee documents are on file yet, so there is nothing to check.', 'medium'),
            ]);
        }

        [$windowLabel, $end] = $this->expiryWindow($q);
        $urgent = (int) config('hris.urgent_days', 7);

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())
            ->orderBy('expiry_date')
            ->get();

        $soon = EmployeeDocument::whereDate('expiry_date', '>=', today())
            ->whereDate('expiry_date', '<=', $end)
            ->orderBy('expiry_date')
            ->get();

        if ($expired->isEmpty() && $soon->isEmpty()) {
            return $this->answer('Documents', [
                $this->row("No document is expired or expires {$windowLabel}. {$total} document(s) on file.", 'low'),
            ]);
        }

        $parts = [];

        if ($expired->isNotEmpty()) {
            $parts[] = "{$expired->count()} expired";
        }

        if ($soon->isNotEmpty()) {
            $parts[] = "{$soon->count()} expiring {$windowLabel}";
        }

        $hasUrgent = $soon->contains(
            fn ($document) => (int) today()->diffInDays($document->expiry_date, true) <= $urgent
        );

        $items = [
            $this->row(
                'Documents: ' . implode(' and ', $parts) . '.',
                $expired->isNotEmpty() ? 'critical' : ($hasUrgent ? 'high' : 'medium'),
                route('hr.compliance')
            ),
        ];

        foreach ($expired->take(5) as $document) {
            $days = (int) $document->expiry_date->diffInDays(today(), true);

            $items[] = $this->row("{$document->employee_name}: {$document->document_type}, expired {$days} day(s) ago", 'high');
        }

        foreach ($soon->take(5) as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            $items[] = $this->row(
                "{$document->employee_name}: {$document->document_type}, {$days} day(s) left",
                $days <= $urgent ? 'high' : 'medium'
            );
        }

        return $this->answer('Documents', $items);
    }

    // Returns [label, last day]. "within 30 days" unless the question says otherwise.
    private function expiryWindow(string $q): array
    {
        if (preg_match('/(?:within|sa loob ng|next|susunod na)\s+(\d{1,3})\s+(?:days?|araw)/u', $q, $match)) {
            $days = max(1, min(365, (int) $match[1]));

            return ["within {$days} days", today()->addDays($days)];
        }

        if ($this->has($q, ['this month', 'ngayong buwan'])) {
            return ['this month', today()->endOfMonth()];
        }

        if ($this->has($q, ['this week', 'ngayong linggo'])) {
            return ['this week', today()->endOfWeek()];
        }

        $days = (int) config('hris.warning_days', 30);

        return ["within {$days} days", today()->addDays($days)];
    }

    private function requiredDocuments(): array
    {
        return array_values(array_filter((array) config('hris.required_employee_documents', [])));
    }

    // The required document types that are not in $types.
    private function missingFrom(Collection $types, array $required): array
    {
        $have = $types->map(fn ($type) => mb_strtolower(trim((string) $type)));

        return array_values(array_filter(
            $required,
            fn ($type) => !$have->contains(mb_strtolower($type))
        ));
    }

    // name => list of missing required documents, only for employees that miss something.
    private function incompleteList(): Collection
    {
        $required = $this->requiredDocuments();

        if (!$required) {
            return collect();
        }

        $types = EmployeeDocument::query()
            ->get(['employee_name', 'document_type'])
            ->groupBy('employee_name')
            ->map(fn (Collection $group) => $group->pluck('document_type'));

        $list = collect();

        foreach ($this->employeeNames() as $name) {
            $missing = $this->missingFrom($types->get($name, collect()), $required);

            if ($missing) {
                $list->put($name, $missing);
            }
        }

        return $list;
    }

    private function incompleteEmployees(): array
    {
        $required = $this->requiredDocuments();

        if (!$required) {
            return $this->answer('Incomplete documents', [
                $this->row('No required documents are set yet. Add them in config/hris.php.', 'medium'),
            ]);
        }

        if ($this->employeeNames()->isEmpty()) {
            return $this->answer('Incomplete documents', [
                $this->row('There are no employees on file yet.', 'medium'),
            ]);
        }

        $list = $this->incompleteList();

        if ($list->isEmpty()) {
            return $this->answer('Incomplete documents', [
                $this->row('Every employee has all ' . count($required) . ' required documents on file.', 'low'),
            ]);
        }

        $items = [
            $this->row("{$list->count()} employee(s) are missing required documents.", 'high', route('hr.compliance')),
        ];

        foreach ($list->take(8) as $name => $missing) {
            $items[] = $this->row("{$name}: " . implode(', ', $missing), 'medium');
        }

        if ($list->count() > 8) {
            $items[] = $this->row('+' . ($list->count() - 8) . ' more');
        }

        $items[] = $this->row('Required: ' . implode(', ', $required) . '.');

        return $this->answer('Incomplete documents', $items);
    }

    // "Is Joshua's record complete?"
    private function personCompleteness(string $name, bool $self = false): array
    {
        $required = $this->requiredDocuments();

        if (!$required) {
            return $this->answer('Documents', [
                $this->row('No required documents are set yet. Add them in config/hris.php.', 'medium'),
            ]);
        }

        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();
        $missing = $this->missingFrom($documents->pluck('document_type'), $required);
        $urgent = (int) config('hris.urgent_days', 7);
        $warning = (int) config('hris.warning_days', 30);

        $items = [
            $this->row(
                ($self ? 'You have ' : "{$name} has ") . (count($required) - count($missing)) . ' of ' . count($required) . ' required documents.',
                $missing ? 'high' : 'low'
            ),
        ];

        foreach ($missing as $type) {
            $items[] = $this->row("Missing: {$type}", 'high');
        }

        $issues = 0;

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row("Expired: {$document->document_type}, {$days} day(s) ago", 'critical');
                $issues++;
            } elseif ($document->expiry_date->lte(today()->addDays($warning))) {
                $items[] = $this->row("Expiring: {$document->document_type}, {$days} day(s) left", $days <= $urgent ? 'high' : 'medium');
                $issues++;
            }
        }

        if (!$missing && $issues === 0) {
            $items[] = $this->row('Nothing is missing, expired, or about to expire.');
        }

        return $this->answer('Documents', $items);
    }

    private function employeeDocuments(string $name, bool $self = false): array
    {
        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        if ($documents->isEmpty()) {
            return $this->answer('Documents', [
                $this->row(($self ? 'You have' : "{$name} has") . ' no documents on file yet.', 'medium'),
            ]);
        }

        $urgent = (int) config('hris.urgent_days', 7);
        $warning = (int) config('hris.warning_days', 30);

        $expired = $documents->filter(fn ($d) => $d->expiry_date->lt(today()))->count();

        $soon = $documents->filter(
            fn ($d) => $d->expiry_date->gte(today()) && $d->expiry_date->lte(today()->addDays($warning))
        )->count();

        $valid = $documents->count() - $expired - $soon;

        $bits = [];

        if ($expired) {
            $bits[] = "{$expired} expired";
        }

        if ($soon) {
            $bits[] = "{$soon} expiring within {$warning} days";
        }

        if ($valid) {
            $bits[] = "{$valid} valid";
        }

        $items = [
            $this->row(
                ($self ? 'Your documents: ' : "Documents of {$name}: ") . implode(', ', $bits) . '.',
                $expired ? 'critical' : ($soon ? 'medium' : 'low')
            ),
        ];

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row("{$document->document_type}: expired {$days} day(s) ago", 'critical');
            } elseif ($document->expiry_date->lte(today()->addDays($warning))) {
                $items[] = $this->row("{$document->document_type}: {$days} day(s) left", $days <= $urgent ? 'high' : 'medium');
            } else {
                $items[] = $this->row("{$document->document_type}: valid until " . $document->expiry_date->format('M j, Y'));
            }
        }

        return $this->answer('Documents', $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Employees and people
    |--------------------------------------------------------------------------
    */

    // Everyone who is an employee: the employees table, plus anyone who only
    // appears in attendance or documents (older records).
    private function employeeNames(): Collection
    {
        return Employee::query()->pluck('name')
            ->merge(AttendanceLog::query()->distinct()->pluck('employee_name'))
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->filter()
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
                $this->row('There are no employees on file yet.', 'medium'),
            ]);
        }

        $people = $names->merge(Applicant::query()->pluck('name'))->unique()->sort()->values();
        $matches = $this->findEmployees($q, $people);

        if ($matches->count() === 1) {
            $person = $matches->first();
            $focus = $this->personFocus($q);

            if ($focus !== null) {
                return $this->personProfile($person, $focus);
            }

            $employee = Employee::where('name', $person)->first();

            $lead = $names->contains($person)
                ? "Yes, {$person} is an employee" . ($employee ? " ({$employee->position}, {$employee->employment_status})" : '') . '.'
                : "No, {$person} is not listed as an employee.";

            return $this->personProfile($person, null, $lead);
        }

        $records = Employee::query()->whereIn('name', $names->all())->get()->keyBy('name');

        // "How many active employees do we have?"
        if ($this->has($q, ['active', 'aktibo'])) {
            $active = $records->filter(
                fn ($employee) => mb_strtolower((string) $employee->employment_status) === 'active'
            );

            $items = [
                $this->row("{$active->count()} active employee(s) out of {$records->count()} on record.", 'high'),
            ];

            foreach ($active->take(10) as $employee) {
                $items[] = $this->row("{$employee->name}, {$employee->position}");
            }

            return $this->answer('Employees', $items);
        }

        $logs = AttendanceLog::whereIn('employee_name', $names->all())
            ->orderBy('time_in')
            ->get()
            ->groupBy('employee_name');

        $items = [$this->row("{$names->count()} employee(s) on file.", 'high')];

        foreach ($names->take(10) as $name) {
            $own = $logs->get($name, collect());
            $record = $records->get($name);

            $open = $own
                ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
                ->last();

            $items[] = $this->row(
                $name
                    . ($record ? ", {$record->position} ({$record->employment_status})" : '')
                    . ': ' . $this->fmt($this->minutes($own)) . ' worked'
                    . ($open ? ', on duty now' : '')
            );
        }

        if ($names->count() > 10) {
            $items[] = $this->row('+' . ($names->count() - 10) . ' more');
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
            $name = $matches->first();
            $focus = $this->personFocus($q);

            // "May applicant ba tayong Kym?": say plainly that the person is on file.
            $lead = ($focus === null && $this->asksExistence($q)) ? $this->existenceText($name) : null;

            return $this->personProfile($name, $focus, $lead);
        }

        if ($matches->count() > 1) {
            return $this->answer('People', [
                $this->row('More than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Write the full name, for example "' . $matches->first() . '".'),
            ]);
        }

        return null;
    }

    private function asksExistence(string $q): bool
    {
        return $this->hasWord($q, ['may', 'meron', 'mayroon', 'merong', 'mayroong'])
            || $this->has($q, ['is there', 'do we have', 'are there', 'exists']);
    }

    private function existenceText(string $name): string
    {
        $kinds = [];

        if (Applicant::where('name', $name)->exists()) {
            $kinds[] = 'an applicant';
        }

        if ($this->employeeNames()->contains($name)) {
            $kinds[] = 'an employee';
        }

        return $kinds
            ? "Yes, {$name} is on file as " . implode(' and ', $kinds) . '.'
            : "No record of {$name} was found.";
    }

    // What the question wants to know about the person, so the answer can be direct.
    private function personFocus(string $q): ?string
    {
        if ($this->asksCompleteness($q)) {
            return 'completeness';
        }

        if (
            $this->hasWord($q, ['role', 'position'])
            || $this->has($q, ['posisyon', 'job title', 'anong trabaho', 'ano trabaho', 'designation'])
        ) {
            return 'role';
        }

        if ($this->has($q, ['employment status', 'date hired', 'hire date', 'hired', 'na hire', 'nahire'])) {
            return 'employment';
        }

        if (
            $this->has($q, self::REJECTED_WORDS)
            || $this->has($q, self::APPROVED_WORDS)
            || $this->has($q, ['decision', 'decided', 'desisyon'])
        ) {
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

    private function applicationSummary(Application $application): string
    {
        $status = self::STATUS_LABELS[$application->status] ?? $application->status;
        $parts = ["{$application->job_title}: {$status}"];

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

        return implode('; ', $parts) . '.';
    }

    private function applicationUrl(Application $application): ?string
    {
        return $application->status === 'draft' ? null : route('hr.applicants.show', $application);
    }

    // The employee record: position, employment status, date hired.
    private function employmentAnswer(string $name, bool $self): array
    {
        $employee = Employee::with('user')->where('name', $name)->first();

        if (!$employee) {
            return $this->answer('Employment', [
                $this->row(($self ? 'Your' : "The") . ' employment record is not on file, so no position is stored.', 'medium'),
            ]);
        }

        $items = [
            $this->row(
                ($self ? 'Your position' : "Position of {$name}") . ": {$employee->position}; employment status: {$employee->employment_status}; date hired: "
                    . ($employee->date_hired ? $employee->date_hired->format('M j, Y') : 'not recorded') . '.',
                'high'
            ),
        ];

        if ($employee->user) {
            $items[] = $this->row('System account role: ' . $employee->user->roleLabel() . '.');
        }

        return $this->answer('Employment', $items);
    }

    // "Anong role ni Joshua?"
    private function personRole(string $name): array
    {
        $employee = Employee::with('user')->where('name', $name)->first();

        if ($employee) {
            $items = [
                $this->row(
                    "{$name} is a {$employee->position} ({$employee->employment_status}), "
                        . ($employee->date_hired ? 'hired ' . $employee->date_hired->format('M j, Y') : 'date hired not recorded') . '.',
                    'high'
                ),
            ];

            if ($employee->user) {
                $items[] = $this->row('System account role: ' . $employee->user->roleLabel() . '.');
            }
        } else {
            $items = [
                $this->row("{$name} is not on file as an employee, so no position is recorded.", 'medium'),
            ];
        }

        foreach ($this->applicationsOf($name) as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $items[] = $this->row("Applied for {$application->job_title}: {$status}.", 'low', $this->applicationUrl($application));
        }

        return $this->answer('Role', $items);
    }

    // "When was Joshua approved?"
    private function personDecision(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer('Decision', [
                $this->row("No application found for {$name}.", 'medium'),
            ]);
        }

        $items = [];

        foreach ($applications as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $head = $application->job_title . ' (submitted '
                . ($application->submitted_at ? $application->submitted_at->format('M j, Y g:i A') : 'date unknown') . ')';

            $text = ($application->reviewed_at && in_array($application->status, ['approved', 'rejected'], true))
                ? "{$name}: {$status} on " . $application->reviewed_at->format('M j, Y g:i A') . " for {$head}."
                : "{$name}: no decision yet for {$head}, current status {$status}.";

            if ($application->remarks) {
                $text .= ' HR remarks: ' . Str::limit($application->remarks, 120);
            }

            $items[] = $this->row(
                $text,
                $application->status === 'rejected' ? 'high' : 'medium',
                $this->applicationUrl($application)
            );
        }

        return $this->answer('Decision', $items);
    }

    // "When is Kym's interview?"
    private function personInterview(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer('Interview', [
                $this->row("No application found for {$name}.", 'medium'),
            ]);
        }

        $items = [];

        foreach ($applications as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            if ($application->interview_at) {
                $text = "{$name}: interview on "
                    . Carbon::parse($application->interview_at)->format('l, M j, Y \a\t g:i A')
                    . ($application->interview_location ? ", {$application->interview_location}" : '')
                    . " for {$application->job_title}.";

                if ($application->interview_completed_at) {
                    $text .= ' Completed ' . Carbon::parse($application->interview_completed_at)->format('M j, Y g:i A') . '.';
                }
            } else {
                $text = "{$name}: no interview scheduled yet for {$application->job_title}, current status {$status}.";
            }

            $items[] = $this->row($text, 'medium', $this->applicationUrl($application));
        }

        return $this->answer('Interview', $items);
    }

    // Everything the system knows about one person, or just the part that was asked.
    private function personProfile(string $name, ?string $focus = null, ?string $lead = null): array
    {
        if ($focus === 'completeness') {
            return $this->personCompleteness($name);
        }

        if ($focus === 'role') {
            return $this->personRole($name);
        }

        if ($focus === 'employment') {
            return $this->employmentAnswer($name, false);
        }

        if ($focus === 'decision') {
            return $this->personDecision($name);
        }

        if ($focus === 'interview') {
            return $this->personInterview($name);
        }

        $employee = Employee::with('user')->where('name', $name)->first();
        $applications = $this->applicationsOf($name);
        $logs = AttendanceLog::where('employee_name', $name)->orderBy('time_in')->get();
        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        $bits = [];

        if ($employee) {
            $bits[] = "{$employee->position}, {$employee->employment_status}";
        }

        if ($applications->isNotEmpty()) {
            $latest = $applications->first();

            $bits[] = 'applicant (' . Str::lower(self::STATUS_LABELS[$latest->status] ?? $latest->status) . ')';
        }

        $lead ??= $bits ? "{$name}: " . implode('; ', $bits) . '.' : "No records found for {$name}.";

        $items = [$this->row($lead, 'medium')];

        if ($employee) {
            $items[] = $this->row(
                'Date hired: ' . ($employee->date_hired ? $employee->date_hired->format('M j, Y') : 'not recorded')
                    . ($employee->user ? '; system account role: ' . $employee->user->roleLabel() : '') . '.'
            );
        }

        foreach ($applications as $application) {
            $items[] = $this->row($this->applicationSummary($application), 'low', $this->applicationUrl($application));
        }

        if ($logs->isNotEmpty()) {
            $items[] = $this->row(
                'Worked: this week ' . $this->fmt($this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek())))
                    . '; this month ' . $this->fmt($this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth())))
                    . '; in total ' . $this->fmt($this->minutes($logs)) . '.'
            );

            $items = array_merge($items, $this->dutyNotes($logs));
        }

        $required = $this->requiredDocuments();

        if ($required && ($documents->isNotEmpty() || $employee)) {
            $missing = $this->missingFrom($documents->pluck('document_type'), $required);

            $items[] = $this->row(
                'Documents: ' . (count($required) - count($missing)) . ' of ' . count($required) . ' required on file'
                    . ($missing ? '; missing ' . implode(', ', $missing) : '') . '.',
                $missing ? 'high' : 'low'
            );
        }

        $urgent = (int) config('hris.urgent_days', 7);
        $warning = (int) config('hris.warning_days', 30);

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row("Expired: {$document->document_type}, {$days} day(s) ago", 'critical');
            } elseif ($document->expiry_date->lte(today()->addDays($warning))) {
                $items[] = $this->row("Expiring: {$document->document_type}, {$days} day(s) left", $days <= $urgent ? 'high' : 'medium');
            }
        }

        return $this->answer('Profile', $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Applicants (HR view)
    |--------------------------------------------------------------------------
    */

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

        if ($this->has($q, self::REJECTED_WORDS)) {
            $statuses = ['rejected'];
            $what = 'rejected';
        } elseif ($this->has($q, self::APPROVED_WORDS)) {
            $statuses = ['approved'];
            $what = 'approved';
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
        $when = $this->when($label, $from);

        if ($interviewStage && $from) {
            $when .= ' (by interview date)';
        }

        $isPending = $statuses === ['submitted', 'under_review'];

        $items = [
            $this->row(
                "{$count} application(s) {$what} {$when}.",
                $count === 0 ? 'low' : ($isPending ? 'high' : 'medium'),
                $count > 0 ? route('hr.applicants') : null
            ),
        ];

        // Breakdown by status, only when the question was not about one status.
        if ($statuses === null && $count > 0) {
            $parts = $applications
                ->groupBy('status')
                ->map(fn (Collection $group, string $status) => (self::STATUS_LABELS[$status] ?? $status) . ' ' . $group->count())
                ->values()
                ->implode(', ');

            $items[] = $this->row("By status: {$parts}.");
        }

        foreach ($applications->take(5) as $application) {
            $name = $application->applicant->name ?? 'Unknown';
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $line = "{$name}: {$application->job_title}, {$status}";

            if ($interviewStage && $application->interview_at) {
                $line .= ', interview ' . Carbon::parse($application->interview_at)->format('M j, g:i A')
                    . ($application->interview_location ? ' at ' . $application->interview_location : '');
            }

            $items[] = $this->row($line, 'low', route('hr.applicants.show', $application));
        }

        if ($count > 5) {
            $items[] = $this->row('+' . ($count - 5) . ' more');
        }

        return $this->answer('Applicants', $items);
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

        $warning = (int) config('hris.warning_days', 30);

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())->count();

        $soon = EmployeeDocument::whereDate('expiry_date', '>=', today())
            ->whereDate('expiry_date', '<=', today()->addDays($warning))
            ->count();

        $incomplete = $this->incompleteList()->count();

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
            $items[] = $this->row("{$soon} document(s) expiring within {$warning} days", 'high', route('hr.compliance'));
        }

        if ($incomplete > 0) {
            $items[] = $this->row("{$incomplete} employee(s) are missing required documents", 'medium', route('hr.compliance'));
        }

        $lead = $items
            ? $this->row(count($items) . ' item(s) need your attention.', 'high')
            : $this->row('Nothing needs your attention right now.', 'low');

        array_unshift($items, $lead);

        $items[] = $this->row("{$onDuty} employee(s) on duty now.");

        return $this->answer('Overview', $items);
    }

    private function identity(string $role, ?string $name): array
    {
        if ($role === 'Applicant' && !$name) {
            return $this->answer('', [
                $this->row('Log in and I can tell you which account you are using.', 'medium'),
            ]);
        }

        if ($name) {
            $kind = $role === 'Employee' ? 'an employee' : 'a job applicant';

            return $this->answer('', [
                $this->row("You are {$name}, {$kind} at Frontier Security Services Inc."),
            ]);
        }

        return $this->answer('', [
            $this->row('You are using the HR portal. I do not know your name because HR has no login in this demo yet.'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Employee (Security Personnel): own records only
    |--------------------------------------------------------------------------
    */

    private function askEmployee(string $q, string $name): ?array
    {
        if ($this->has($q, self::HOURS_WORDS)) {
            [$label, $from, $to] = $this->range($q);

            return $this->hoursFor($name, $label, $from, $to, true);
        }

        if ($this->has($q, self::ATTENDANCE_WORDS)) {
            [$label, $from, $to] = $this->range($q);

            return $this->attendanceFor($name, $label, $from, $to, true);
        }

        if ($this->hasWord($q, self::SCHEDULE_WORDS) || $this->has($q, self::SCHEDULE_PHRASES)) {
            $records = $this->attendanceFor($name, 'All time', null, null, true);

            return $this->answer('Attendance', array_merge($this->scheduleNotes(), $records['items']));
        }

        if ($this->asksCompleteness($q) && $this->has($q, self::DOCUMENT_WORDS)) {
            return $this->personCompleteness($name, true);
        }

        if ($this->has($q, self::DOCUMENT_WORDS)) {
            return $this->employeeDocuments($name, true);
        }

        if (
            $this->hasWord($q, ['role', 'position'])
            || $this->has($q, ['posisyon', 'employment status', 'date hired', 'hired', 'na hire', 'nahire'])
        ) {
            return $this->employmentAnswer($name, true);
        }

        return $this->isGreeting($q) ? $this->help('Employee', 'How can I help?') : null;
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
            return $this->answer('', [
                $this->row('Log in to ask about your application.', 'medium'),
            ]);
        }

        $application = $applicant->applications()->latest('id')->first();

        if (!$application) {
            return $this->answer('Application', [
                $this->row('You have not started an application yet.', 'medium'),
                $this->row('Click "Start Application" on your dashboard to begin.'),
            ]);
        }

        $label = self::STATUS_LABELS[$application->status] ?? ucfirst($application->status);

        if ($intent === 'interview') {
            if ($application->interview_at) {
                $when = Carbon::parse($application->interview_at);

                $items = [$this->row('Your interview: ' . $when->format('l, M j, Y \a\t g:i A') . '.', 'high')];

                if ($application->interview_location) {
                    $items[] = $this->row("Location: {$application->interview_location}", 'medium');
                }

                return $this->answer('Interview', $items);
            }

            return $this->answer('Interview', [
                $this->row('No interview is scheduled yet.', 'medium'),
                $this->row($this->nextStep($application)),
            ]);
        }

        if ($intent === 'next') {
            return $this->answer('Next step', [
                $this->row($this->nextStep($application), 'high'),
            ]);
        }

        $level = in_array($application->status, ['approved', 'rejected'], true) ? 'high' : 'medium';

        $items = [$this->row("Your application for {$application->job_title}: {$label}.", $level)];

        if ($application->remarks) {
            $items[] = $this->row("HR remarks: {$application->remarks}", 'medium');
        }

        $items[] = $this->row('Next: ' . $this->nextStep($application));

        return $this->answer('Application', $items);
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
    | Data helpers
    |--------------------------------------------------------------------------
    */

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

    // "today", "last month"... or "in total" when no period was asked.
    private function when(string $label, ?Carbon $from): string
    {
        return $from ? Str::lower($label) : 'in total';
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
    | Answer shape
    |--------------------------------------------------------------------------
    |
    | title: a small label above the answer (can be empty).
    | lead:  true means the first row is the direct answer, shown as a sentence.
    | items: the rows. The first one is the lead, the others are bullets.
    |
    */

    private function row(string $text, string $level = 'low', ?string $url = null, ?string $tag = null): array
    {
        return compact('text', 'level', 'url', 'tag');
    }

    private function answer(string $title, array $items): array
    {
        return [
            'title' => $title,
            'lead' => $items !== [],
            'items' => array_values($items),
        ];
    }
}