<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\RobotEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ApplicantDashboardController extends Controller
{
    public function index(Applicant $applicant)
    {
        $application = $applicant->applications()
            ->latest('id')
            ->first();

        return view('applicant.dashboard', [
            'applicant' => $applicant,
            'application' => $application,
        ]);
    }

    /**
     * Start a draft application (nothing is sent to HR yet).
     */
    public function start(Applicant $applicant): RedirectResponse
    {
        $existing = $applicant->applications()->first();

        if (!$existing) {
            Application::create([
                'applicant_id' => $applicant->id,
                'job_title' => 'Security Guard',
                'status' => 'draft',
                'step' => 1,
            ]);
        }

        return redirect()->route('applicant.dashboard', $applicant);
    }

    /**
     * Save step 1 or step 2, then move to the next step.
     */
    public function saveStep(
        Request $request,
        Applicant $applicant
    ): RedirectResponse {
        $application = $applicant->applications()
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        if ($application->step === 1) {

            $data = $request->validate([
                'phone' => ['required', 'string', 'max:30'],
                'address' => ['required', 'string', 'max:255'],
            ]);

            $application->update([
                'phone' => $data['phone'],
                'address' => $data['address'],
                'step' => 2,
            ]);

        } elseif ($application->step === 2) {

            $request->validate([
                'documents' => ['required', 'array', 'size:4'],
            ], [
                'documents.size' => 'Please confirm all 4 required documents.',
                'documents.required' => 'Please confirm all 4 required documents.',
            ]);

            $application->update([
                'step' => 3,
            ]);
        }

        return redirect()->route('applicant.dashboard', $applicant);
    }

    /**
     * Final submit: now HR gets the robot event.
     */
    public function submit(Applicant $applicant): RedirectResponse
    {
        $application = $applicant->applications()
            ->where('status', 'draft')
            ->latest('id')
            ->firstOrFail();

        abort_unless($application->step === 3, 403);

        $application->update([
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);

        RobotEvent::create([
            'role' => 'HR',
            'type' => 'application_submitted',
            'message' => "{$applicant->name} just submitted an application for {$application->job_title}.",
            'priority' => 'high',
            'related_type' => 'Application',
            'related_id' => $application->id,
            'is_read' => false,
        ]);

        return redirect()
            ->route('applicant.dashboard', $applicant)
            ->with(
                'success',
                'Your application has been submitted successfully.'
            );
    }
}