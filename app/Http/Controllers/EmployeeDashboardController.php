<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\RobotEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EmployeeDashboardController extends Controller
{
    public function index()
    {
        $employee = $this->currentEmployee();

        $documents = $this->documentsFor($employee)
            ->orderBy('expiry_date')
            ->get();

        $expiredCount = $documents
            ->filter(fn ($document) => $document->expiry_date->lt(today()))
            ->count();

        $expiringCount = $documents
            ->filter(fn ($document) =>
                $document->expiry_date->gte(today())
                && $document->expiry_date->lte(today()->addDays(30))
            )
            ->count();

        return view('employee.dashboard', [
            'employeeName' => $employee->name,
            'documents' => $documents,
            'expiredCount' => $expiredCount,
            'expiringCount' => $expiringCount,
        ]);
    }

    public function renew(
        Request $request,
        EmployeeDocument $document
    ): RedirectResponse {
        $employee = $this->currentEmployee();

        $ownsDocument = (
            $document->employee_id !== null
            && (int) $document->employee_id === (int) $employee->id
        ) || (
            $document->employee_id === null
            && $document->employee_name === $employee->name
        );

        abort_unless(
            $ownsDocument,
            403,
            'You cannot renew another employee’s document.'
        );

        $currentExpiry = $document->expiry_date->toDateString();

        $data = $request->validate([
            'new_expiry' => [
                'required',
                'date',
                'after:today',
                "after:{$currentExpiry}",
            ],
        ], [
            'new_expiry.required' => 'Please choose the new expiry date.',
            'new_expiry.after' => 'The new expiry date must be in the future and later than the current expiry date.',
        ]);

        // Link legacy name-only records to the authenticated employee.
        $document->update([
            'employee_id' => $employee->id,
            'employee_name' => $employee->name,
            'expiry_date' => $data['new_expiry'],
        ]);

        RobotEvent::create([
            'role' => 'HR',
            'type' => 'document_renewed',
            'message' => $employee->name
                . " renewed the {$document->document_type}.",
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

    private function currentEmployee(): Employee
    {
        $user = Auth::guard('web')->user();

        abort_unless(
            $user instanceof User && $user->isSecurity(),
            403,
            'This page is for Security Personnel only.'
        );

        $employee = $user->employee;

        abort_unless(
            $employee instanceof Employee,
            403,
            'Your staff account is not linked to an employee record.'
        );

        return $employee;
    }

    private function documentsFor(Employee $employee): Builder
    {
        return EmployeeDocument::query()
            ->where(function (Builder $query) use ($employee) {
                $query->where('employee_id', $employee->id)
                    ->orWhere(function (Builder $legacy) use ($employee) {
                        $legacy->whereNull('employee_id')
                            ->where('employee_name', $employee->name);
                    });
            });
    }
}