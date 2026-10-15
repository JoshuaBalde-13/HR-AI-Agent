<?php

namespace App\Services\AI;

use App\Models\Application;
use App\Services\AI\Concerns\AnswerHelpers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Applications: counts and lists for HR, and the guide for the applicant.
 */
class ApplicantAnswers
{
    use AnswerHelpers;

    public const VERSION = 5;

    public const STATUS_LABELS = [
        'draft' => 'In progress',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'interview_scheduled' => 'Interview scheduled',
        'interview_completed' => 'Interview completed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // Checked first: "hindi natanggap" also contains "natanggap".
    public const REJECTED_WORDS = [
        'rejected', 'reject', 'denied', 'hindi natanggap', 'hindi tinanggap', 'hindi pumasa', 'bagsak',
    ];

    public const APPROVED_WORDS = [
        'approved', 'approve', 'tinanggap', 'natanggap', 'nakapasa', 'pumasa',
    ];

    /*
    |--------------------------------------------------------------------------
    | HR: counts and lists
    |--------------------------------------------------------------------------
    */

    public function applicants(string $q): array
    {
        [$label, $from, $to] = $this->range($q);
        $yes = $this->isYesNo($q);

        // Applicants who have not submitted yet.
        if ($this->has($q, ['in progress', 'draft', 'filling', 'incomplete'])) {
            $count = Application::where('status', 'draft')->count();

            $text = $count === 0
                ? 'nobody is filling out an application right now.'
                : $this->countOf($count, 'applicant') . ($count === 1 ? ' is' : ' are') . ' still filling out an application.';

            return $this->answer('Applicants', [
                $this->row($this->verdict($yes, $count > 0, $text, ucfirst($text)), $count ? 'medium' : 'low'),
            ]);
        }

        $statuses = null;
        $what = 'submitted';
        $state = false;

        if ($this->has($q, self::REJECTED_WORDS)) {
            $statuses = ['rejected'];
            $what = 'rejected';
        } elseif ($this->has($q, self::APPROVED_WORDS)) {
            $statuses = ['approved'];
            $what = 'approved';
        } elseif ($this->has($q, ['interview'])) {
            $statuses = ['interview_scheduled', 'interview_completed'];
            $what = 'in the interview stage';
            $state = true;
        } elseif ($this->has($q, ['pending', 'review', 'waiting', 'hintay', 'naghihintay'])) {
            $statuses = ['submitted', 'under_review'];
            $what = 'waiting for review';
            $state = true;
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

        // A state ("waiting for review") needs no period when none was asked.
        $suffix = ($state && !$from) ? '' : ' ' . $this->when($label, $from) . ($interviewStage && $from ? ' (by interview date)' : '');

        $be = $state ? ($count === 1 ? 'is' : 'are') : ($count === 1 ? 'was' : 'were');

        $some = $this->countOf($count, 'application') . " {$be} {$what}{$suffix}.";
        $none = 'no application ' . ($state ? 'is' : 'was') . " {$what}{$suffix}.";

        $items = [
            $this->row(
                $this->verdict($yes, $count > 0, $count > 0 ? $some : $none, $count > 0 ? $some : ucfirst($none)),
                $count === 0 ? 'low' : ($statuses === ['submitted', 'under_review'] ? 'high' : 'medium'),
                $count > 0 ? route('hr.applicants') : null
            ),
        ];

        // "Ilan?" is only a number. "Sino?" and plain questions list the people.
        if ($count > 0 && !$this->isCount($q)) {
            foreach ($applications->take($this->isWho($q) ? 8 : 5) as $application) {
                $name = $application->applicant->name ?? 'Unknown';
                $status = self::STATUS_LABELS[$application->status] ?? $application->status;

                $line = "{$name}: {$application->job_title}, {$status}";

                if ($interviewStage && $application->interview_at) {
                    $line .= ', interview ' . Carbon::parse($application->interview_at)->format('M j, g:i A')
                        . ($application->interview_location ? ' at ' . $application->interview_location : '');
                }

                $items[] = $this->row($line, 'low', $this->url($application));
            }

            if ($count > ($this->isWho($q) ? 8 : 5)) {
                $items[] = $this->row('+' . ($count - ($this->isWho($q) ? 8 : 5)) . ' more');
            }
        }

        return $this->answer('Applicants', $items, $count === 1 ? ($applications->first()->applicant->name ?? null) : null);
    }

    /*
    |--------------------------------------------------------------------------
    | HR: one named person
    |--------------------------------------------------------------------------
    */

    public function applicationsOf(string $name): Collection
    {
        return Application::with('applicant')
            ->whereHas('applicant', fn ($query) => $query->where('name', $name))
            ->latest('id')
            ->take(3)
            ->get();
    }

    public function url(Application $application): ?string
    {
        return $application->status === 'draft' ? null : route('hr.applicants.show', $application);
    }

    // One line about an application, for a full profile.
    public function summary(Application $application): string
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

    // "When was Joshua approved?": the date, nothing else.
    public function decision(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer('Decision', [$this->row("No application found for {$name}.", 'medium')], $name);
        }

        $items = [];

        foreach ($applications as $application) {
            $status = Str::lower(self::STATUS_LABELS[$application->status] ?? $application->status);

            $text = ($application->reviewed_at && in_array($application->status, ['approved', 'rejected'], true))
                ? "{$name} was {$status} on " . $application->reviewed_at->format('M j, Y g:i A') . '.'
                : "The application of {$name} has no decision yet (status: {$status}).";

            if ($applications->count() > 1 && $application->submitted_at) {
                $text .= ' (submitted ' . $application->submitted_at->format('M j, g:i A') . ')';
            }

            $items[] = $this->row($text, $application->status === 'rejected' ? 'high' : 'medium', $this->url($application));
        }

        return $this->answer('Decision', $items, $name);
    }

    // "When is Kym's interview?": the date, nothing else.
    public function interview(string $name): array
    {
        $applications = $this->applicationsOf($name);

        if ($applications->isEmpty()) {
            return $this->answer('Interview', [$this->row("No application found for {$name}.", 'medium')], $name);
        }

        $items = [];

        foreach ($applications as $application) {
            $text = $application->interview_at
                ? "The interview of {$name} is on "
                    . Carbon::parse($application->interview_at)->format('l, M j, Y \a\t g:i A')
                    . ($application->interview_location ? ", {$application->interview_location}" : '') . '.'
                : "No interview is scheduled yet for {$name}.";

            if ($applications->count() > 1 && $application->submitted_at) {
                $text .= ' (application submitted ' . $application->submitted_at->format('M j, g:i A') . ')';
            }

            $items[] = $this->row($text, 'medium', $this->url($application));
        }

        return $this->answer('Interview', $items, $name);
    }

    /*
    |--------------------------------------------------------------------------
    | Applicant (always the logged-in applicant, never an id from the request)
    |--------------------------------------------------------------------------
    */

    public function forApplicant(string $q, array $help): ?array
    {
        $intent = match (true) {
            $this->has($q, ['interview', 'schedule', 'iskedyul']) => 'interview',
            $this->has($q, ['next', 'susunod', 'what do i do', 'gagawin']) => 'next',
            $this->has($q, ['status', 'application', 'where is my', 'nasaan', 'any update', 'result']) => 'status',
            default => null,
        };

        if ($intent === null) {
            return null;
        }

        $applicant = Auth::guard('applicant')->user();

        if (!$applicant) {
            return $this->answer('', [$this->row('Log in to ask about your application.', 'medium')]);
        }

        $application = $applicant->applications()->latest('id')->first();

        if (!$application) {
            return $this->answer('Application', [
                $this->row('You have not started an application yet. Click "Start Application" on your dashboard to begin.', 'medium'),
            ]);
        }

        $label = self::STATUS_LABELS[$application->status] ?? ucfirst($application->status);

        if ($intent === 'interview') {
            if (!$application->interview_at) {
                return $this->answer('Interview', [
                    $this->row('No interview is scheduled yet.', 'medium'),
                ]);
            }

            $when = Carbon::parse($application->interview_at);

            return $this->answer('Interview', [
                $this->row(
                    'Your interview is on ' . $when->format('l, M j, Y \a\t g:i A')
                        . ($application->interview_location ? ", {$application->interview_location}" : '') . '.',
                    'high'
                ),
            ]);
        }

        if ($intent === 'next') {
            return $this->answer('Next step', [$this->row($this->nextStep($application), 'high')]);
        }

        $items = [
            $this->row(
                "Your application for {$application->job_title} is {$label}.",
                in_array($application->status, ['approved', 'rejected'], true) ? 'high' : 'medium'
            ),
        ];

        // The reason is only shown when the applicant asks for it.
        if ($application->remarks && $this->has($q, ['remarks', 'reason', 'bakit', 'why'])) {
            $items[] = $this->row("HR remarks: {$application->remarks}", 'medium');
        }

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
}