<?php

namespace Database\Seeders;

use App\Models\AttendanceLog;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    /**
     * Demo attendance for the robot's "worked hours" questions.
     */
    public function run(): void
    {
        // Safe to run more than once: remove the old demo rows first.
        AttendanceLog::whereIn('employee_name', [
            'Joshua Balde',
            'Juan Dela Cruz',
        ])->delete();

        foreach (range(1, 10) as $i) {
            $day = now()->subDays($i)->startOfDay();

            if ($day->isSunday()) {
                continue;
            }

            // Joshua: day shift, 8:00 AM to 5:00 PM (9 hours)
            AttendanceLog::create([
                'employee_name' => 'Joshua Balde',
                'time_in' => $day->copy()->setTime(8, 0),
                'time_out' => $day->copy()->setTime(17, 0),
            ]);

            // Juan: night shift, 6:00 PM to 6:00 AM the next day (12 hours)
            AttendanceLog::create([
                'employee_name' => 'Juan Dela Cruz',
                'time_in' => $day->copy()->setTime(18, 0),
                'time_out' => $day->copy()->addDay()->setTime(6, 0),
            ]);
        }

        // Joshua is clocked in today and has not clocked out yet.
        AttendanceLog::create([
            'employee_name' => 'Joshua Balde',
            'time_in' => today()->setTime(8, 0),
        ]);
    }
}