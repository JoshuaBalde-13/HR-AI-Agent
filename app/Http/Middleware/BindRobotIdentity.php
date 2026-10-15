<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class BindRobotIdentity
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {
        $staff = Auth::guard('web')->user();

        if ($staff instanceof User) {
            if ($staff->hasRole(User::ROLE_ADMIN, User::ROLE_HR)) {
                $role = 'HR';
                $employeeName = null;
            } elseif ($staff->isSecurity()) {
                $employee = $staff->employee;

                if (!$employee) {
                    return response()->json([
                        'message' => 'Your account is not linked to an employee record.',
                    ], 403);
                }

                $role = 'Employee';
                $employeeName = $employee->name;
            } else {
                return response()->json([
                    'message' => 'Your account cannot access the HRIS AI Agent.',
                ], 403);
            }
        } else {
            $applicant = Auth::guard('applicant')->user();

            if (!$applicant) {
                return response()->json([
                    'message' => 'Please log in to use the HRIS AI Agent.',
                ], 401);
            }

            $role = 'Applicant';
            $employeeName = null;
        }

        // Overwrite the untrusted values supplied by the browser.
        $trustedIdentity = [
            'role' => $role,
            'employee_name' => $employeeName,
        ];

        $request->merge($trustedIdentity);

        // GET endpoints read query parameters, so replace those too.
        $request->query->set('role', $role);
        $request->query->set('employee_name', $employeeName);

        // Available to other controllers for authorization checks.
        $request->attributes->set('hris.role', $role);
        $request->attributes->set('hris.employee_name', $employeeName);

        return $next($request);
    }
}