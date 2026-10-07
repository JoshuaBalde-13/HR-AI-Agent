<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\RobotEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HRApplicantController extends Controller
{
    /**
     * Which statuses each action is allowed to start from.
     */
    private const REQUIRES = [
        'start_review' => ['submitted'],
        'schedule_interview' => ['under_review', 'interview_scheduled'],
        'complete_interview' => ['interview_scheduled'],
        'approve' => ['interview_completed'],
        'reject' => ['interview_completed'],
    ];

    public function index(Request $request)
    {
        $filter = $request->query('status', 'pending');

        if (!in_array($filter, ['pending', 'interview', 'approved', 'rejected', 'all'], true)) {
            $filter = 'pending';
        }

        // Drafts are still being filled out by the applicant,
        // so HR never sees them here.
        $query = Application::with('applicant')
            ->where('status', '!=', 'draft');

        if ($filter === 'pending') {
            $query->pending();
        } elseif ($filter === 'interview') {
            $query->whereIn('status', ['interview_scheduled', 'interview_completed']);
        } elseif ($filter === 'approved') {
            $query->where('status', 'approved');
        } elseif ($filter === 'rejected') {
            $query->where('status', 'rejected');
        }

        // Interviews tab: soonest interview first.
        if ($filter === 'interview') {
            $query->orderBy('interview_at');
        } else {
            $query->latest('submitted_at');
        }

        $applications = $query->get();

        $counts = [
            'pending' => Application::pending()->count(),
            'interview' => Application::whereIn(
                'status',
                ['interview_scheduled', 'interview_completed']
            )->count(),
            'approved' => Application::where('status', 'approved')->count(),
            'rejected' => Application::where('status', 'rejected')->count(),
            'all' => Application::where('status', '!=', 'draft')->count(),
        ];

        return view('hr.applicants', [
            'applications' => $applications,
            'filter' => $filter,
            'counts' => $counts,
        ]);
    }

    public function show(Application $application)
    {
        abort_if($application->status === 'draft', 404);

        $application->load('applicant');

        return view('hr.applicant-review', [
            'application' => $application,
        ]);
    }

    public function review(
        Request $request,
        Application $application
    ): RedirectResponse {
        abort_if($application->status === 'draft', 404);

        $data = $request->validate([
            'action' => [
                'required',
                'in:start_review,schedule_interview,complete_interview,approve,reject',
            ],
            'interview_at' => ['nullable', 'date', 'after:now', 'required_if:action,schedule_interview'],
            'interview_location' => ['nullable', 'string', 'max:255'],
            'remarks' => ['nullable', 'string', 'max:500', 'required_if:action,reject'],
        ], [
            'interview_at.required_if' => 'Please choose the interview date and time.',
            'interview_at.after' => 'The interview must be set to a future date and time.',
            'remarks.required_if' => 'Please write a reason when rejecting an application.',
        ]);

        $action = $data['action'];

        // The server decides what is allowed, never the page,
        // so an old tab cannot skip a step.
        if (!in_array($application->status, self::REQUIRES[$action], true)) {

            if (in_array($application->status, ['approved', 'rejected'], true)) {
                $error = 'This application has already been decided.';
            } else {
                $error = match ($action) {
                    'approve', 'reject' => 'Complete the interview first before making a decision.',
                    'complete_interview' => 'Schedule an interview first.',
                    'schedule_interview' => 'Start the review before scheduling an interview.',
                    default => 'This step is not available for the current status.',
                };
            }

            return back()->withErrors(['action' => $error]);
        }

        if ($action === 'start_review') {

            $application->update(['status' => 'under_review']);

            $message = 'Review started. Next: schedule an interview.';

        } elseif ($action === 'schedule_interview') {

            $rescheduled = $application->status === 'interview_scheduled';

            $application->update([
                'status' => 'interview_scheduled',
                'interview_at' => $data['interview_at'],
                'interview_location' => $data['interview_location'] ?? null,
                'interview_completed_at' => null,
            ]);

            $message = $rescheduled
                ? 'Interview rescheduled.'
                : 'Interview scheduled.';

        } elseif ($action === 'complete_interview') {

            $application->update([
                'status' => 'interview_completed',
                'interview_completed_at' => now(),
            ]);

            $message = 'Interview marked as completed. You can now approve or reject.';

        } elseif ($action === 'approve') {

            $application->update([
                'status' => 'approved',
                'remarks' => $data['remarks'] ?? null,
                'reviewed_at' => now(),
            ]);

            $message = 'Application approved.';

        } else {

            $application->update([
                'status' => 'rejected',
                'remarks' => $data['remarks'],
                'reviewed_at' => now(),
            ]);

            $message = 'Application rejected.';
        }

        // HR has acted on it, so its "new application" robot alert
        // is no longer needed.
        RobotEvent::where('role', 'HR')
            ->where('type', 'application_submitted')
            ->where('related_id', $application->id)
            ->update(['is_read' => true]);

        return redirect()
            ->route('hr.applicants.show', $application)
            ->with('success', $message);
    }
}