<?php

namespace App\Services\AI;

use App\Models\Applicant;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\AI\Concerns\AnswerHelpers;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * People: employees, and anything about one named person. Each answer is
 * limited to what the question asks.
 */
class PeopleAnswers
{
    use AnswerHelpers;

    public const VERSION = 5;

    public const EMPLOYEE_WORDS = ['employee', 'empleyado', 'staff', 'personnel'];

    public function __construct(
        private AttendanceAnswers $attendance,
        private ComplianceAnswers $compliance,
        private ApplicantAnswers $applicants,
    ) {
    }

    /*
    |--------------------------------------------------------------------------
    | Employees
    |--------------------------------------------------------------------------
    */

    // "How many employees do we have?" or "Is Joshua Balde an employee?"
    public function employees(string $q): array
    {
        $names = $this->employeeNames();

        if ($names->isEmpty()) {
            return $this->answer('Employees', [
                $this->row('There are no employees on file yet.', 'medium'),
            ]);
        }

        $matches = $this->findEmployees($q, $this->allPeople());

        if ($matches->count() === 1) {
            return $this->about($matches->first(), $q);
        }

        $yes = $this->isYesNo($q);
        $records = Employee::query()->whereIn('name', $names->all())->get()->keyBy('name');

        // "How many active employees do we have?"
        if ($this->has($q, ['active', 'aktibo'])) {
            $active = $records->filter(
                fn ($employee) => mb_strtolower((string) $employee->employment_status) === 'active'
            );

            $items = [
                $this->row($this->verdict($yes, $active->count() > 0, 'we have ' . $this->countOf($active->count(), 'active employee') . '.', 'We have ' . $this->countOf($active->count(), 'active employee') . '.'), 'high'),
            ];

            if (!$this->isCount($q)) {
                foreach ($active->take(10) as $employee) {
                    $items[] = $this->row("{$employee->name}, {$employee->position}");
                }
            }

            return $this->answer('Employees', $items);
        }

        $text = 'we have ' . $this->countOf($names->count(), 'employee') . ' on file.';

        $items = [$this->row($this->verdict($yes, true, $text, ucfirst($text)), 'high')];

        // "Ilan?" is only a number. Otherwise list them.
        if (!$this->isCount($q)) {
            $logs = AttendanceLog::whereIn('employee_name', $names->all())
                ->orderBy('time_in')
                ->get()
                ->groupBy('employee_name');

            foreach ($names->take(10) as $name) {
                $record = $records->get($name);

                $items[] = $this->row(
                    $name . ($record ? ", {$record->position} ({$record->employment_status})" : '')
                        . ': ' . $this->fmt($this->attendance->minutes($logs->get($name, collect()))) . ' worked'
                );
            }

            if ($names->count() > 10) {
                $items[] = $this->row('+' . ($names->count() - 10) . ' more');
            }
        }

        return $this->answer('Employees', $items);
    }

    /*
    |--------------------------------------------------------------------------
    | One named person
    |--------------------------------------------------------------------------
    */

    // A person (applicant or employee) is named in the question.
    public function person(string $q): ?array
    {
        $matches = $this->findEmployees($q, $this->allPeople());

        if ($matches->count() === 1) {
            return $this->about($matches->first(), $q);
        }

        if ($matches->count() > 1) {
            return $this->answer('People', [
                $this->row('More than one match: ' . $matches->implode(', ') . '. Please write the full name.', 'medium'),
            ]);
        }

        return null;
    }

    // What the question wants to know about the person.
    public function focus(string $q): ?string
    {
        if ($this->asksCompleteness($q)) {
            return 'completeness';
        }

        if ($this->has($q, [
            'since when', 'kailan naging', 'naging employee', 'become our employee', 'became',
            'date hired', 'hire date', 'na hire', 'nahire', 'hired', 'start date',
        ])) {
            return 'hired';
        }

        if ($this->has($q, ['employment status'])) {
            return 'employment_status';
        }

        if (
            $this->hasWord($q, ['role', 'position'])
            || $this->has($q, ['posisyon', 'job title', 'anong trabaho', 'ano trabaho', 'designation'])
        ) {
            return 'role';
        }

        if (
            $this->has($q, ApplicantAnswers::REJECTED_WORDS)
            || $this->has($q, ApplicantAnswers::APPROVED_WORDS)
            || $this->has($q, ['decision', 'decided', 'desisyon'])
        ) {
            return 'decision';
        }

        if ($this->has($q, ['interview'])) {
            return 'interview';
        }

        if ($this->hasWord($q, ['status'])) {
            return 'status';
        }

        return null;
    }

    public function about(string $name, string $q): array
    {
        return match ($this->focus($q)) {
            'completeness' => $this->compliance->completeness($name, false, $q),
            'hired', 'employment_status', 'role' => $this->employment($name, $q),
            'decision' => $this->applicants->decision($name),
            'interview' => $this->applicants->interview($name),
            'status' => $this->status($name),
            default => ($this->isYesNo($q) || $this->asksExistence($q))
                ? $this->existence($name, $q)
                : $this->profile($name),
        };
    }

    // "Anong role ni Joshua?" "Since when is he an employee?" "Employment status?"
    private function employment(string $name, string $q): array
    {
        $employee = Employee::with('user')->where('name', $name)->first();
        $focus = $this->focus($q);

        if (!$employee) {
            $latest = $this->applicants->applicationsOf($name)->first();

            return $this->answer('Employment', [
                $this->row(
                    "{$name} is not on file as an employee, so no position is recorded."
                        . ($latest ? " They applied for {$latest->job_title} ("
                            . Str::lower(ApplicantAnswers::STATUS_LABELS[$latest->status] ?? $latest->status) . ').' : ''),
                    'medium'
                ),
            ], $name);
        }

        if ($focus === 'hired') {
            $text = $employee->date_hired
                ? ($this->has($q, ['since when'])
                    ? "{$name} has been an employee since " . $employee->date_hired->format('M j, Y') . '.'
                    : "{$name} was hired on " . $employee->date_hired->format('M j, Y') . '.')
                : "{$name} is an employee, but the date hired is not recorded.";
        } elseif ($focus === 'employment_status') {
            $text = "The employment status of {$name} is {$employee->employment_status}.";
        } else {
            $text = "{$name} is a {$employee->position}"
                . ($employee->user ? " (system role: {$employee->user->roleLabel()})" : '') . '.';
        }

        return $this->answer('Employment', [$this->row($text, 'high')], $name);
    }

    // The employee's own record (Security Personnel asking about themselves).
    public function employmentFor(string $name, string $q): array
    {
        $employee = Employee::where('name', $name)->first();

        if (!$employee) {
            return $this->answer('Employment', [
                $this->row('Your employment record is not on file yet.', 'medium'),
            ]);
        }

        $focus = $this->focus($q);

        $text = match ($focus) {
            'hired' => $employee->date_hired
                ? ($this->has($q, ['since when']) ? 'You have been an employee since ' : 'You were hired on ') . $employee->date_hired->format('M j, Y') . '.'
                : 'Your date hired is not recorded.',
            'employment_status' => "Your employment status is {$employee->employment_status}.",
            default => "Your position is {$employee->position}.",
        };

        return $this->answer('Employment', [$this->row($text, 'high')]);
    }

    // "Status ni Kym": the status only.
    private function status(string $name): array
    {
        $employee = Employee::where('name', $name)->first();
        $applications = $this->applicants->applicationsOf($name);

        $bits = [];

        if ($employee) {
            $bits[] = "employee ({$employee->employment_status})";
        }

        $latest = $applications->first();

        if ($latest) {
            $bits[] = 'application ' . Str::lower(ApplicantAnswers::STATUS_LABELS[$latest->status] ?? $latest->status)
                . " for {$latest->job_title}";
        }

        $lead = $bits ? "{$name}: " . implode('; ', $bits) . '.' : "No records found for {$name}.";

        return $this->answer('Status', [
            $this->row($lead, 'high', $latest ? $this->applicants->url($latest) : null),
        ], $name);
    }

    // "May applicant ba tayong Kym?": yes or no, about the kind of record that was asked.
    private function existence(string $name, string $q): array
    {
        $application = $this->applicants->applicationsOf($name)->first();
        $isApplicant = $application !== null;
        $employee = Employee::where('name', $name)->first();
        $isEmployee = $employee !== null || $this->employeeNames()->contains($name);

        $wantApplicant = $this->has($q, ['applicant', 'aplikante']);
        $wantEmployee = $this->has($q, self::EMPLOYEE_WORDS);

        $statusText = $application
            ? ' (' . ApplicantAnswers::STATUS_LABELS[$application->status] ?? $application->status . ')'
            : '';

        if ($application) {
            $statusText = ' (' . (ApplicantAnswers::STATUS_LABELS[$application->status] ?? $application->status) . ')';
        }

        $employeeText = $employee ? " ({$employee->position}, {$employee->employment_status})" : '';

        if ($wantApplicant && !$wantEmployee) {
            $text = $isApplicant
                ? $this->verdict(true, true, "{$name} is on file as an applicant{$statusText}.")
                : $this->verdict(true, false, "{$name} is not on file as an applicant.");
        } elseif ($wantEmployee && !$wantApplicant) {
            $text = $isEmployee
                ? $this->verdict(true, true, "{$name} is an employee{$employeeText}.")
                : $this->verdict(true, false, "{$name} is not listed as an employee.");
        } else {
            $kinds = [];

            if ($isApplicant) {
                $kinds[] = 'an applicant';
            }

            if ($isEmployee) {
                $kinds[] = 'an employee';
            }

            $text = $kinds
                ? $this->verdict(true, true, "{$name} is on file as " . implode(' and ', $kinds) . '.')
                : $this->verdict(true, false, "{$name} was not found.");
        }

        return $this->answer('People', [$this->row($text, 'high')], $name);
    }

    // Everything about one person. Only for "tell me about ..." style questions.
    public function profile(string $name): array
    {
        $employee = Employee::with('user')->where('name', $name)->first();
        $applications = $this->applicants->applicationsOf($name);
        $logs = AttendanceLog::where('employee_name', $name)->orderBy('time_in')->get();
        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        $bits = [];

        if ($employee) {
            $bits[] = "{$employee->position}, {$employee->employment_status}";
        }

        if ($applications->isNotEmpty()) {
            $latest = $applications->first();

            $bits[] = 'applicant (' . Str::lower(ApplicantAnswers::STATUS_LABELS[$latest->status] ?? $latest->status) . ')';
        }

        $items = [$this->row($bits ? "{$name}: " . implode('; ', $bits) . '.' : "No records found for {$name}.", 'medium')];

        if ($employee) {
            $items[] = $this->row(
                'Date hired: ' . ($employee->date_hired ? $employee->date_hired->format('M j, Y') : 'not recorded')
                    . ($employee->user ? '; system role: ' . $employee->user->roleLabel() : '') . '.'
            );
        }

        foreach ($applications as $application) {
            $items[] = $this->row($this->applicants->summary($application), 'low', $this->applicants->url($application));
        }

        if ($logs->isNotEmpty()) {
            $items[] = $this->row('Worked ' . $this->fmt($this->attendance->minutes($logs)) . ' in total.');
            $items = array_merge($items, $this->attendance->dutyNotes($logs));
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

        return $this->answer('Profile', $items, $name);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function allPeople(): Collection
    {
        return $this->employeeNames()
            ->merge(Applicant::query()->pluck('name'))
            ->unique()
            ->sort()
            ->values();
    }

    private function asksExistence(string $q): bool
    {
        return $this->hasWord($q, ['may', 'meron', 'mayroon', 'merong', 'mayroong'])
            || $this->has($q, ['is there', 'do we have', 'are there', 'exists']);
    }
}