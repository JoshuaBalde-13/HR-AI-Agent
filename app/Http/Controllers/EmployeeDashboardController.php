<?php

namespace App\Http\Controllers;

use App\Models\EmployeeDocument;
use App\Models\RobotEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmployeeDashboardController extends Controller
{
    // No login yet, so the demo employee is fixed.
    private const EMPLOYEE = 'Juan Dela Cruz';

    public function index()
    {
        $documents = EmployeeDocument::where('employee_name', self::EMPLOYEE)
            ->orderBy('expiry_date')
            ->get();

        $expiredCount = $documents
            ->filter(fn ($d) => $d->expiry_date->lt(today()))
            ->count();

        $expiringCount = $documents
            ->filter(fn ($d) => $d->expiry_date->gte(today())
                && $d->expiry_date->lte(today()->addDays(30)))
            ->count();

        return view('employee.dashboard', [
            'employeeName' => self::EMPLOYEE,
            'documents' => $documents,
            'expiredCount' => $expiredCount,
            'expiringCount' => $expiringCount,
        ]);
    }

    public function renew(
        Request $request,
        EmployeeDocument $document
    ): RedirectResponse {
        // An employee may only renew their own documents.
        abort_unless($document->employee_name === self::EMPLOYEE, 403);

        $current = $document->expiry_date->toDateString();

        $data = $request->validate([
            'new_expiry' => [
                'required',
                'date',
                'after:today',
                "after:{$current}",
            ],
        ], [
            'new_expiry.required' => 'Please choose the new expiry date.',
            'new_expiry.after' => "The new expiry date must be in the future and later than the current one ({$document->expiry_date->format('M d, Y')}).",
        ]);

        $document->update([
            'expiry_date' => $data['new_expiry'],
        ]);

        // HR gets notified through the robot.
        RobotEvent::create([
            'role' => 'HR',
            'type' => 'document_renewed',
            'message' => self::EMPLOYEE . " renewed the {$document->document_type}.",
            'priority' => 'medium',
            'related_type' => 'EmployeeDocument',
            'related_id' => $document->id,
            'is_read' => false,
        ]);

        return redirect()
            ->route('employee.dashboard')
            ->with(
                'success',
                "Your {$document->document_type} has been updated."
            );
    }
}