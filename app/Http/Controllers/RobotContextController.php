<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\EmployeeDocument;
use App\Models\RobotEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RobotContextController extends Controller
{
    private const RANK = ['low' => 0, 'medium' => 1, 'high' => 2, 'critical' => 3];

    public function show(Request $request)
    {
        $role = $request->query('role', 'HR');
        $page = $request->query('page', 'dashboard');

        $context = match ($role) {
            'Applicant' => $this->applicant(),
            'Employee' => $this->employee($request),
            default => $this->hr($page),
        };

        $items = $context['items'];

        // Highest priority among the items decides the robot's mood.
        $priority = 'low';
        foreach ($items as $item) {
            if (self::RANK[$item['level']] > self::RANK[$priority]) {
                $priority = $item['level'];
            }
        }

        // First item that links somewhere becomes the action button.
        $actionUrl = collect($items)->pluck('url')->filter()->first();

        // Fallback for an older robot component that only reads "message".
        $message = $context['title'] . ': ' . collect($items)->pluck('text')->implode('; ');

        return response()->json([
            'role' => $role,
            'page' => $page,
            'title' => $context['title'],
            'items' => $items,
            'message' => $message,
            'priority' => $priority,
            'action_url' => $actionUrl,
            'event_id' => $context['event_id'] ?? null,
            'unread_count' => $context['unread_count'] ?? 0,
        ]);
    }

    private function item(
        string $text,
        string $level = 'low',
        ?string $url = null,
        string $icon = '•'
    ): array {
        return compact('text', 'level', 'url', 'icon');
    }

    /*
    |--------------------------------------------------------------------------
    | HR
    |--------------------------------------------------------------------------
    */

    private function hr(string $page): array
    {
        $counts = Application::where('status', '!=', 'draft')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $new = (int) ($counts['submitted'] ?? 0);
        $underReview = (int) ($counts['under_review'] ?? 0);
        $scheduled = (int) ($counts['interview_scheduled'] ?? 0);
        $interviewed = (int) ($counts['interview_completed'] ?? 0);

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())->count();

        $todayApplications = Application::where('status', '!=', 'draft')
            ->whereDate('submitted_at', today())
            ->count();

        $unreadCount = RobotEvent::where('role', 'HR')
            ->where('is_read', false)
            ->count();

        $latestEvent = RobotEvent::where('role', 'HR')
            ->where('is_read', false)
            ->latest('id')
            ->first();

        $items = [];

        if ($latestEvent) {
            $url = ($latestEvent->type === 'application_submitted' && $page !== 'applicants')
                ? route('hr.applicants')
                : null;

            $items[] = $this->item(
                $latestEvent->message,
                $latestEvent->priority ?? 'medium',
                $url,
                ''
            );
        }

        // One row per stage of the hiring flow.
        $appUrl = $page !== 'applicants' ? route('hr.applicants') : null;
        $appItems = [];

        if ($new > 0) {
            $appItems[] = $this->item("{$new} new application(s) to review", 'high', $appUrl, '');
        }

        if ($underReview > 0) {
            $appItems[] = $this->item("{$underReview} under review – schedule an interview", 'medium', $appUrl, '');
        }

        if ($scheduled > 0) {
            $appItems[] = $this->item("{$scheduled} interview(s) scheduled", 'medium', $appUrl, '');
        }

        if ($interviewed > 0) {
            $appItems[] = $this->item("{$interviewed} interview(s) done – ready for your decision", 'high', $appUrl, '');
        }

        $expiredItems = [];

        if ($expired > 0) {
            $expiredItems[] = $this->item(
                "{$expired} expired employee document(s)",
                'critical',
                $page !== 'compliance' ? route('hr.compliance') : null,
                ''
            );
        }

        // Whatever page HR is on comes first.
        $ordered = $page === 'applicants'
            ? array_merge($appItems, $expiredItems)
            : array_merge($expiredItems, $appItems);

        $items = array_merge($items, $ordered);

        if (!$items) {
            $items[] = $this->item('Nothing needs your attention right now', 'low', null, '');
            $items[] = $this->item("{$todayApplications} application(s) received today", 'low', null, '');
        }

        $needsAttention = collect($items)->contains(fn ($i) => $i['level'] !== 'low');

        return [
            'title' => $needsAttention ? 'HR – needs your attention' : 'HR – all clear',
            'items' => $items,
            'event_id' => $latestEvent?->id,
            'unread_count' => $unreadCount,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Applicant (guide)
    |--------------------------------------------------------------------------
    */

    private function applicant(): array
    {
        // The logged-in applicant, never an id taken from the URL.
        $applicant = Auth::guard('applicant')->user();

        if (!$applicant) {
            return [
                'title' => 'Hello!',
                'items' => [$this->item('Log in to see your application', 'low', null, '')],
            ];
        }

        $application = $applicant->applications()->latest('id')->first();

        if (!$application) {
            return [
                'title' => "Welcome, {$applicant->name}",
                'items' => [
                    $this->item('Applying takes 3 short steps: Personal Info → Documents → Review', 'medium', null, ''),
                    $this->item('Click "Start Application" to apply for Security Guard', 'medium', null, ''),
                ],
            ];
        }

        $job = $application->job_title;

        $when = $application->interview_at?->format('D, M j, Y \a\t g:i A');

        return match ($application->status) {
            'draft' => $this->applicantDraft((int) $application->step),

            'submitted' => [
                'title' => 'Application submitted',
                'items' => [
                    $this->item("Your application for {$job} is in", 'low', null, ''),
                    $this->item('HR will review it soon', 'low', null, ''),
                    $this->item('Nothing else to do – this page updates by itself', 'low', null, ''),
                ],
            ],

            'under_review' => [
                'title' => 'Under review',
                'items' => [
                    $this->item("HR is reviewing your application for {$job}", 'medium', null, ''),
                    $this->item('Next: HR will set your interview schedule', 'low', null, ''),
                    $this->item('You will see it here as soon as it is set', 'low', null, ''),
                ],
            ],

            'interview_scheduled' => [
                'title' => 'Interview scheduled',
                'items' => array_values(array_filter([
                    $this->item("Your interview: {$when}", 'high', null, ''),
                    $application->interview_location
                        ? $this->item("Location: {$application->interview_location}", 'medium', null, '')
                        : null,
                    $this->item('Please arrive a few minutes early', 'medium', null, ''),
                    $this->item('If the schedule changes, it will update here', 'low', null, ''),
                ])),
            ],

            'interview_completed' => [
                'title' => 'Interview completed',
                'items' => [
                    $this->item('Thank you for attending your interview', 'medium', null, ''),
                    $this->item('HR is making the final decision', 'medium', null, ''),
                    $this->item('The result will appear here as soon as it is ready', 'low', null, ''),
                ],
            ],

            'approved' => [
                'title' => 'Congratulations!',
                'items' => array_values(array_filter([
                    $this->item("Your application for {$job} was approved", 'high', null, ''),
                    $application->remarks
                        ? $this->item("HR note: {$application->remarks}", 'medium', null, '')
                        : null,
                ])),
            ],

            'rejected' => [
                'title' => 'Application update',
                'items' => array_values(array_filter([
                    $this->item("Your application for {$job} was not approved this time", 'high', null, ''),
                    $application->remarks
                        ? $this->item("Reason: {$application->remarks}", 'medium', null, '')
                        : null,
                ])),
            ],

            default => [
                'title' => 'Application',
                'items' => [$this->item('Your application is being processed', 'low', null, '')],
            ],
        };
    }

    private function applicantDraft(int $step): array
    {
        return match ($step) {
            1 => [
                'title' => 'Step 1 of 3 – Personal Info',
                'items' => [
                    $this->item('Enter your active phone number', 'medium', null, ''),
                    $this->item('Enter your complete address', 'medium', null, ''),
                    $this->item('Click Continue to save and go to Step 2', 'low', null, ''),
                ],
            ],
            2 => [
                'title' => 'Step 2 of 3 – Documents',
                'items' => [
                    $this->item('Get these ready: Government ID, NBI Clearance, Medical Certificate, Security Training Certificate', 'medium', null, ''),
                    $this->item('Tick each one once you have it', 'medium', null, ''),
                    $this->item('Click Continue when all 4 are ticked', 'low', null, ''),
                ],
            ],
            3 => [
                'title' => 'Step 3 of 3 – Review',
                'items' => [
                    $this->item('Check that your phone and address are correct', 'medium', null, ''),
                    $this->item('Click Submit Application to send it to HR', 'high', null, ''),
                ],
            ],
            default => [
                'title' => 'Application in progress',
                'items' => [$this->item('Continue where you left off', 'medium', null, '')],
            ],
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Employee
    |--------------------------------------------------------------------------
    */

    private function employee(Request $request): array
    {
        $name = $request->query('employee_name', 'Juan Dela Cruz');

        $expired = EmployeeDocument::where('employee_name', $name)
            ->whereDate('expiry_date', '<', today())
            ->count();

        $expiring = EmployeeDocument::where('employee_name', $name)
            ->whereBetween('expiry_date', [today(), today()->addDays(30)])
            ->count();

        $items = [];

        if ($expired > 0) {
            $items[] = $this->item("{$expired} expired document(s) – please renew", 'critical', null, '');
        }

        if ($expiring > 0) {
            $items[] = $this->item("{$expiring} document(s) expiring within 30 days", 'high', null, '');
        }

        if (!$items) {
            $items[] = $this->item('All your required documents are up to date', 'low', null, '');
        }

        return [
            'title' => "Hello, {$name}",
            'items' => $items,
        ];
    }
}