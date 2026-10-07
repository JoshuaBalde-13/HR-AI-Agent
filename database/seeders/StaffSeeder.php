<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo staff, employees and documents. Safe to run more than once.
 * The password of every demo account is "password". Demo only.
 */
class StaffSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Staff accounts, one per role (two Security Personnel).
        $this->user('System Administrator', 'admin@frontier.test', User::ROLE_ADMIN);
        $this->user('HR Personnel', 'hr@frontier.test', User::ROLE_HR);
        $juan = $this->user('Juan Dela Cruz', 'juan@frontier.test', User::ROLE_SECURITY);
        $joshua = $this->user('Joshua Balde', 'joshua@frontier.test', User::ROLE_SECURITY);

        // 2. Employees: everyone who already has attendance or documents,
        //    plus the two Security Personnel accounts.
        $names = AttendanceLog::query()->distinct()->pluck('employee_name')
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->merge(['Juan Dela Cruz', 'Joshua Balde'])
            ->filter()
            ->unique()
            ->values();

        foreach ($names as $name) {
            Employee::firstOrCreate(
                ['name' => $name],
                [
                    'position' => 'Security Guard',
                    'employment_status' => 'Active',
                    'date_hired' => now()->subMonths(4)->toDateString(),
                ]
            );
        }

        Employee::where('name', 'Juan Dela Cruz')->update(['user_id' => $juan->id]);
        Employee::where('name', 'Joshua Balde')->update(['user_id' => $joshua->id]);

        // 3. Link the existing attendance and documents to their employee by name.
        foreach (Employee::all() as $employee) {
            AttendanceLog::where('employee_name', $employee->name)
                ->update(['employee_id' => $employee->id]);

            EmployeeDocument::where('employee_name', $employee->name)
                ->update(['employee_id' => $employee->id]);
        }

        // 4. Demo documents, dated from today, so the compliance questions have something to show.
        //    Joshua has no Government ID on purpose (an incomplete record).
        $this->documents('Juan Dela Cruz', [
            'Government ID' => 400,
            'NBI Clearance' => 250,
            'Medical Certificate' => 20,
            'Security License' => 180,
        ]);

        $this->documents('Joshua Balde', [
            'NBI Clearance' => 200,
            'Medical Certificate' => 12,
            'Security License' => -5,
        ]);

        $this->command?->info(sprintf(
            'Users: %d | Employees: %d | Documents: %d | Attendance linked: %d of %d',
            User::count(),
            Employee::count(),
            EmployeeDocument::count(),
            AttendanceLog::whereNotNull('employee_id')->count(),
            AttendanceLog::count()
        ));
    }

    private function user(string $name, string $email, string $role): User
    {
        return User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'role' => $role, 'password' => 'password']
        );
    }

    // $types maps a document type to the number of days from today until it expires.
    private function documents(string $employeeName, array $types): void
    {
        $employee = Employee::where('name', $employeeName)->first();

        if (!$employee) {
            return;
        }

        foreach ($types as $type => $days) {
            EmployeeDocument::updateOrCreate(
                ['employee_id' => $employee->id, 'document_type' => $type],
                [
                    'employee_name' => $employee->name,
                    'expiry_date' => today()->addDays($days)->toDateString(),
                ]
            );
        }
    }
}