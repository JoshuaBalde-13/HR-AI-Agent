<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\AttendanceLog;
use App\Models\EmployeeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Builds the live facts the assistant may use to answer.
 *
 * Every line has an id (S1, A2, E1, D3, G1...). The model must name the ids
 * it used, and the app shows those exact lines under the answer.
 * Each role only gets the data that role is allowed to see.
 */
class SystemKnowledge
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

    /**
     * @return array{text: string, rows: array<string, string>}
     */
    public function forRole(string $role, ?string $employeeName): array
    {
        $sections = match ($role) {
            'HR' => $this->hr(),
            'Employee' => $this->employee($employeeName ?: 'Juan Dela Cruz'),
            default => $this->applicant(),
        };

        $rows = [];
        $lines = ['Today is ' . now()->format('l, F j, Y') . '.'];

        foreach ($sections as $title => $items) {
            $lines[] = '';
            $lines[] = "## {$title}";

            foreach ($items as $id => $text) {
                $rows[$id] = $text;
                $lines[] = "{$id} {$text}";
            }
        }

        return [
            'text' => implode("\n", $lines),
            'rows' => $rows,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | HR: sees everything
    |--------------------------------------------------------------------------
    */

    private function hr(): array
    {
        $apps = Application::with('applicant')->orderByDesc('id')->limit(500)->get();
        $submitted = $apps->where('status', '!=', 'draft');

        $byStatus = [];

        foreach (self::STATUS_LABELS as $status => $label) {
            $byStatus[] = $label . ' ' . $apps->where('status', $status)->count();
        }

        $applicationTotals = [
            'S1' => 'Submitted applications (drafts not counted): total ' . $submitted->count()
                . '; today ' . $this->countBetween($submitted, today(), today())
                . '; yesterday ' . $this->countBetween($submitted, today()->subDay(), today()->subDay())
                . '; this week ' . $this->countBetween($submitted, today()->startOfWeek(), today()->endOfWeek())
                . '; this month ' . $this->countBetween($submitted, today()->startOfMonth(), today()->endOfMonth()) . '.',
            'S2' => 'Applications by status: ' . implode(', ', $byStatus) . '.',
            'S3' => 'Registered applicant accounts: ' . Applicant::count()
                . ', of which ' . Applicant::doesntHave('applications')->count()
                . ' have not started an application.',
        ];

        $applicants = [];

        foreach ($apps->take(12)->values() as $index => $app) {
            $applicants['A' . ($index + 1)] = $this->applicationLine($app, true);
        }

        // Employees come from attendance and from documents.
        $names = AttendanceLog::query()->distinct()->pluck('employee_name')
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->unique()
            ->sort()
            ->values()
            ->take(12);

        $logsByName = $names->isEmpty()
            ? collect()
            : AttendanceLog::whereIn('employee_name', $names)
                ->orderBy('time_in')
                ->get()
                ->groupBy('employee_name');

        $all = $logsByName->flatten(1);

        $onNow = $all->filter(
            fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24)
        );

        $employeeTotals = [
            'S4' => 'Employees on file (attendance or documents): ' . $names->count()
                . ($names->isNotEmpty() ? ' (' . $names->implode(', ') . ')' : '') . '.',
            'S5' => 'Worked hours of all employees together: ' . $this->hoursLine($all) . '.',
            'S6' => 'On duty now: ' . ($onNow->isEmpty()
                ? 'nobody'
                : $onNow->map(fn ($log) => $log->employee_name . ' since ' . $log->time_in->format('g:i A'))->implode(', ')) . '.',
            'S7' => $this->topLine('this week', $logsByName, today()->startOfWeek(), today()->endOfWeek()),
            'S8' => $this->topLine('this month', $logsByName, today()->startOfMonth(), today()->endOfMonth()),
            'S9' => $this->topLine('all time', $logsByName, null, null),
        ];

        $employees = [];

        foreach ($names as $index => $name) {
            $logs = $logsByName->get($name, collect());

            $employees['E' . ($index + 1)] = "{$name}: " . $this->hoursLine($logs)
                . $this->dutyTail($logs) . '.';
        }

        $docs = EmployeeDocument::orderBy('expiry_date')->get();

        $expired = $docs->filter(fn ($d) => $d->expiry_date->lt(today()))->count();

        $soon = $docs->filter(
            fn ($d) => $d->expiry_date->gte(today()) && $d->expiry_date->lte(today()->addDays(30))
        )->count();

        $documents = [
            'S10' => "Employee documents: total {$docs->count()}; expired {$expired}; expiring within 30 days {$soon}; valid "
                . ($docs->count() - $expired - $soon) . '.',
        ];

        foreach ($docs->take(15)->values() as $index => $doc) {
            $documents['D' . ($index + 1)] = "{$doc->employee_name}: {$doc->document_type} expires "
                . $doc->expiry_date->format('M j, Y') . ' (' . $this->documentState($doc) . ').';
        }

        return [
            'APPLICATION TOTALS' => $applicationTotals,
            'APPLICANTS (latest 12)' => $applicants,
            'EMPLOYEE TOTALS' => $employeeTotals,
            'EMPLOYEES' => $employees,
            'DOCUMENTS' => $documents,
            'HOW THE SYSTEM WORKS' => [
                'G1' => 'HR Applicants page: tabs Pending, Approved, Rejected and All. Open an application to review it and move it through the stages (a reason is required when rejecting).',
                'G2' => 'HR Compliance page: lists employee documents that are expired or expire within 30 days.',
                'G3' => 'The HR dashboard shows applicant counts, pending reviews and expired documents. The robot gives an alert when a new application arrives.',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Employee: only their own data
    |--------------------------------------------------------------------------
    */

    private function employee(string $name): array
    {
        $logs = AttendanceLog::where('employee_name', $name)->orderBy('time_in')->get();

        $hours = $logs->isEmpty()
            ? ['E1' => 'You have no attendance records yet.']
            : ['E1' => "{$name} (you): " . $this->hoursLine($logs) . $this->dutyTail($logs) . '.'];

        $recent = $logs->filter(fn ($log) => $log->time_out !== null)->take(-5)->values();

        foreach ($recent as $index => $log) {
            $hours['H' . ($index + 1)] = 'Shift on ' . $log->time_in->format('M j') . ': '
                . $log->time_in->format('g:i A') . ' to ' . $log->time_out->format('g:i A')
                . ' (' . $this->fmt((int) round($log->time_in->diffInMinutes($log->time_out, true))) . ').';
        }

        $docs = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        $documents = [];

        foreach ($docs->values() as $index => $doc) {
            $documents['D' . ($index + 1)] = "{$doc->document_type} expires "
                . $doc->expiry_date->format('M j, Y') . ' (' . $this->documentState($doc) . ').';
        }

        if (!$documents) {
            $documents['D1'] = 'You have no documents on file.';
        }

        return [
            'YOU' => [
                'U1' => "You are {$name}, an employee of Frontier Security Services Inc.",
            ],
            'YOUR WORKED HOURS' => $hours,
            'YOUR DOCUMENTS' => $documents,
            'HOW THE SYSTEM WORKS' => [
                'G1' => 'To renew a document: open My Documents, choose a new expiry date that is later than the current one, then click Renew. HR is notified.',
                'G2' => 'A document is Expired when its date has passed, and shows days left when it ends within 30 days.',
                'G3' => 'You can only see your own hours and documents. Information about other people or company-wide numbers is only for HR.',
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Applicant: only their own application
    |--------------------------------------------------------------------------
    */

    private function applicant(): array
    {
        $applicant = Auth::guard('applicant')->user();

        $guide = [
            'G1' => 'How to apply: click Start Application, then Step 1 personal information (phone and address), Step 2 confirm the 4 required documents (Government ID, NBI Clearance, Medical Certificate, Security Training Certificate), Step 3 review and Submit.',
            'G2' => 'Stages: Submitted, Under review, Interview scheduled, Interview completed, then Approved or Rejected. HR moves the application along and your dashboard updates by itself.',
            'G3' => 'You can only see your own application. Information about other applicants or employees is only for HR.',
        ];

        if (!$applicant) {
            return [
                'YOU' => ['U1' => 'The user is not logged in.'],
                'HOW THE SYSTEM WORKS' => $guide,
            ];
        }

        $app = $applicant->applications()->latest('id')->first();

        $mine = $app
            ? [
                'P1' => 'Your application: ' . $this->applicationLine($app, false),
                'P2' => 'Your contact details on the application: phone ' . ($app->phone ?: 'not given')
                    . '; address ' . ($app->address ?: 'not given') . '.',
            ]
            : ['P1' => 'You have not started an application yet.'];

        return [
            'YOU' => [
                'U1' => "You are {$applicant->name}, a job applicant at Frontier Security Services Inc.",
            ],
            'YOUR APPLICATION' => $mine,
            'HOW THE SYSTEM WORKS' => $guide,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    private function applicationLine(Application $app, bool $withName): string
    {
        $status = self::STATUS_LABELS[$app->status] ?? $app->status;
        $head = $withName ? ($app->applicant->name ?? 'Unknown') . ': ' : '';

        $parts = ["{$head}{$app->job_title}, status {$status}"];

        if ($app->status === 'draft') {
            $parts[] = 'form step ' . (int) $app->step . ' of 3';
        }

        if ($app->submitted_at) {
            $parts[] = 'submitted ' . $app->submitted_at->format('M j, Y g:i A');
        }

        if ($app->interview_at) {
            $parts[] = 'interview ' . Carbon::parse($app->interview_at)->format('M j, Y g:i A')
                . ($app->interview_location ? ' at ' . $app->interview_location : '');
        }

        if ($app->reviewed_at) {
            $parts[] = 'decided ' . $app->reviewed_at->format('M j, Y g:i A');
        }

        if ($app->remarks) {
            $parts[] = 'HR remarks: ' . Str::limit($app->remarks, 80);
        }

        return implode('; ', $parts) . '.';
    }

    private function countBetween(Collection $apps, ?Carbon $from, ?Carbon $to): int
    {
        return $apps->filter(function ($app) use ($from, $to) {
            $at = $app->submitted_at;

            if (!$at) {
                return false;
            }

            if ($from && $at < $from->copy()->startOfDay()) {
                return false;
            }

            if ($to && $at > $to->copy()->endOfDay()) {
                return false;
            }

            return true;
        })->count();
    }

    private function periods(): array
    {
        return [
            'today' => [today(), today()],
            'yesterday' => [today()->subDay(), today()->subDay()],
            'this week' => [today()->startOfWeek(), today()->endOfWeek()],
            'this month' => [today()->startOfMonth(), today()->endOfMonth()],
            'all time' => [null, null],
        ];
    }

    // "today 0 h; yesterday 9 h; ...; all time 81 h (9 finished shifts in total)"
    private function hoursLine(Collection $logs): string
    {
        $parts = [];

        foreach ($this->periods() as $label => [$from, $to]) {
            $parts[] = "{$label} " . $this->fmt($this->minutes($logs, $from, $to));
        }

        $shifts = $logs->filter(fn ($log) => $log->time_out !== null)->count();

        return implode('; ', $parts) . " ({$shifts} finished shifts in total)";
    }

    private function dutyTail(Collection $logs): string
    {
        $open = $logs
            ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
            ->last();

        $last = $logs->filter(fn ($log) => $log->time_out !== null)->last();

        return ($open ? '; on duty now since ' . $open->time_in->format('g:i A') : '; not on duty now')
            . ($last ? '; last finished shift ended ' . $last->time_out->format('M j, g:i A') : '');
    }

    private function topLine(string $label, Collection $logsByName, ?Carbon $from, ?Carbon $to): string
    {
        $best = $logsByName
            ->map(fn ($logs) => $this->minutes($logs, $from, $to))
            ->sortDesc();

        $top = $best->first();

        if (!$top) {
            return "Most worked hours {$label}: nobody has finished a shift.";
        }

        return "Most worked hours {$label}: " . $best->keys()->first() . ' (' . $this->fmt((int) $top) . ').';
    }

    // Only finished shifts count. A shift belongs to the day it started.
    private function minutes(Collection $logs, ?Carbon $from, ?Carbon $to): int
    {
        return (int) round(
            $logs
                ->filter(function ($log) use ($from, $to) {
                    if ($log->time_out === null) {
                        return false;
                    }

                    if ($from && $log->time_in < $from->copy()->startOfDay()) {
                        return false;
                    }

                    if ($to && $log->time_in > $to->copy()->endOfDay()) {
                        return false;
                    }

                    return true;
                })
                ->sum(fn ($log) => $log->time_in->diffInMinutes($log->time_out, true))
        );
    }

    private function fmt(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? "{$hours} h {$rest} min" : "{$hours} h";
    }

    private function documentState(EmployeeDocument $doc): string
    {
        $days = (int) today()->diffInDays($doc->expiry_date, true);

        if ($doc->expiry_date->lt(today())) {
            return "expired {$days} day(s) ago";
        }

        if ($doc->expiry_date->lte(today()->addDays(30))) {
            return "{$days} day(s) left";
        }

        return 'valid';
    }
}