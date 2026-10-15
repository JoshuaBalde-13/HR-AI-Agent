<?php

namespace App\Services\AI;

use App\Models\AttendanceLog;
use App\Services\AI\Concerns\AnswerHelpers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Worked hours, attendance, who is on duty. Exact answers from attendance_logs.
 */
class AttendanceAnswers
{
    use AnswerHelpers;

    public const VERSION = 5;

    // Specific on purpose, so a general chat question is not mistaken for data.
    public const HOURS_WORDS = [
        'worked', 'work hour', 'working hour', 'duty hour', 'total hour', 'how many hours',
        'hours of', 'hours ni', 'hours this', 'hours today', 'hours yesterday', 'my hours',
        'ilang oras', 'oras ni', 'oras ng', 'oras ang',
        'pinakamaraming oras', 'pinakakaunting oras', 'most hours', 'least hours',
        'fewest hours', 'highest hours',
        'nagtrabaho', 'nag trabaho', 'nagwork', 'nag work',
    ];

    public const ATTENDANCE_WORDS = [
        'ilang beses', 'ilang shift', 'how many times', 'how many shifts', 'shifts',
        'attendance', 'dtr', 'time in', 'time out', 'clock in', 'clocked in',
        'huling pumasok', 'kailan huling', 'last time', 'pumasok', 'pumapasok',
    ];

    public const DUTY_WORDS = ['on duty', 'naka duty', 'nasa duty', 'who is working', 'on shift'];

    // Questions the system cannot answer because it has no shift schedule.
    public const SCHEDULE_WORDS = [
        'late', 'tardy', 'tardiness', 'absent', 'absence', 'absences',
        'undertime', 'overtime', 'nahuli', 'lumiban',
    ];

    public const SCHEDULE_PHRASES = ['shift schedule', 'duty schedule', 'work schedule'];

    public const AVAILABLE_WORDS = ['available', 'libre'];

    /*
    |--------------------------------------------------------------------------
    | Worked hours
    |--------------------------------------------------------------------------
    */

    // HR: hours of one employee, of everyone, or who worked the most.
    public function hours(string $q): array
    {
        [$label, $from, $to] = $this->range($q);

        $names = AttendanceLog::query()
            ->distinct()
            ->orderBy('employee_name')
            ->pluck('employee_name');

        if ($names->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row('There are no attendance records yet.', 'medium'),
            ]);
        }

        $matches = $this->findEmployees($q, $names);

        if ($matches->count() > 1) {
            return $this->answer('Worked hours', [
                $this->row('More than one match: ' . $matches->implode(', ') . '. Please write the full name.', 'medium'),
            ]);
        }

        if ($matches->count() === 1) {
            return $this->hoursFor($matches->first(), $label, $from, $to);
        }

        // A name was typed but nobody matches it.
        $asked = $this->mentionedName($q);

        if ($asked) {
            return $this->answer('Worked hours', [
                $this->row("I can't find \"{$asked}\" among the employees with attendance records.", 'medium'),
            ]);
        }

        $byName = $this->logs(null, $from, $to)
            ->groupBy('employee_name')
            ->map(fn (Collection $group) => $this->minutes($group))
            ->sortDesc();

        if ($byName->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row('No attendance records ' . ($from ? 'for ' . Str::lower($label) : 'yet') . '.', 'medium'),
            ]);
        }

        $when = $this->when($label, $from);
        $ranking = $this->rankingWanted($q);

        // "Who worked the most?" gets only the answer.
        if ($ranking !== null && $byName->first() > 0) {
            $top = $ranking === 'most' ? $byName->keys()->first() : $byName->keys()->last();
            $minutes = $ranking === 'most' ? $byName->first() : $byName->last();

            return $this->answer('Worked hours', [
                $this->row("{$top} worked the " . ($ranking === 'most' ? 'most' : 'least') . " {$when}: " . $this->fmt((int) $minutes) . '.', 'high'),
            ], $top);
        }

        $items = [$this->row('Everyone together worked ' . $this->fmt((int) $byName->sum()) . " {$when}.", 'high')];

        foreach ($byName->take(6) as $name => $minutes) {
            $items[] = $this->row("{$name}: " . $this->fmt((int) $minutes));
        }

        return $this->answer('Worked hours', $items);
    }

    public function hoursFor(string $name, string $label, ?Carbon $from, ?Carbon $to, bool $self = false): array
    {
        $subject = $self ? 'You' : $name;
        $person = $self ? null : $name;
        $logs = $this->logs($name, $from, $to);

        if ($logs->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row("No attendance records found for {$subject}" . ($from ? ' ' . Str::lower($label) : '') . '.', 'medium'),
            ], $person);
        }

        $closed = $logs->filter(fn ($log) => $log->time_out !== null);

        $items = [
            $this->row(
                "{$subject} worked " . $this->fmt($this->minutes($closed)) . ' ' . $this->when($label, $from)
                    . ' (' . $this->countOf($closed->count(), 'shift') . ').',
                'high'
            ),
        ];

        return $this->answer('Worked hours', array_merge($items, $this->dutyNotes($logs)), $person);
    }

    private function rankingWanted(string $q): ?string
    {
        if ($this->has($q, ['pinakakaunti', 'pinakakaunting', 'least', 'fewest', 'lowest'])) {
            return 'least';
        }

        if (
            $this->has($q, ['pinakamaraming', 'pinakamarami', 'most hours', 'highest'])
            || $this->hasWord($q, ['most', 'top'])
        ) {
            return 'most';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Attendance
    |--------------------------------------------------------------------------
    */

    // HR: about one named employee, or about who is on duty.
    public function attendance(string $q): array
    {
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() === 1) {
            return $this->attendanceFor($matches->first(), $q);
        }

        if ($matches->count() > 1) {
            return $this->answer('Attendance', [
                $this->row('More than one match: ' . $matches->implode(', ') . '. Please write the full name.', 'medium'),
            ]);
        }

        return $this->onDuty($q);
    }

    // Answers only what was asked: on duty now? clocked in? last time? how many times?
    public function attendanceFor(string $name, string $q, bool $self = false): array
    {
        [$label, $from, $to] = $this->range($q);

        $subject = $self ? 'You' : $name;
        $mid = $self ? 'you' : $name;
        $has = $self ? 'have' : 'has';
        $is = $self ? 'are' : 'is';
        $person = $self ? null : $name;
        $yes = $this->isYesNo($q);

        // "Nasa duty ba siya ngayon?"
        if ($this->has($q, self::DUTY_WORDS)) {
            $open = AttendanceLog::where('employee_name', $name)
                ->whereNull('time_out')
                ->where('time_in', '>=', now()->subHours(24))
                ->orderByDesc('time_in')
                ->first();

            $text = $open
                ? $this->say($yes, true, $mid, $subject, "{$is} on duty now, since " . $open->time_in->format('g:i A') . '.')
                : $this->say($yes, false, $mid, $subject, "{$is} not on duty right now.");

            return $this->answer('Attendance', [$this->row($text, $open ? 'high' : 'low')], $person);
        }

        // "Kailan huling pumasok si Joshua?"
        if ($this->has($q, ['huling pumasok', 'kailan huling', 'last time', 'last clock', 'last attendance', 'huling attendance'])) {
            $last = AttendanceLog::where('employee_name', $name)->orderByDesc('time_in')->first();

            if (!$last) {
                return $this->answer('Attendance', [
                    $this->row("No attendance records found for {$mid}.", 'medium'),
                ], $person);
            }

            $text = "{$subject} last clocked in on " . $last->time_in->format('M j, g:i A')
                . ($last->time_out ? ', out ' . $last->time_out->format('g:i A') . '.' : ' (no time out recorded).');

            return $this->answer('Attendance', [$this->row($text, 'high')], $person);
        }

        // "Pumasok ba si Joshua ngayon?" (today unless another period is said)
        if ($yes && $this->has($q, ['pumasok', 'pumapasok', 'clocked in', 'clock in', 'time in'])) {
            [$periodLabel, $periodFrom, $periodTo] = $from ? [$label, $from, $to] : ['Today', today(), today()];

            $logs = $this->logs($name, $periodFrom, $periodTo);
            $when = Str::lower($periodLabel);

            if ($logs->isEmpty()) {
                return $this->answer('Attendance', [
                    $this->row($this->say(true, false, $mid, $subject, "{$has} not clocked in {$when}."), 'medium'),
                ], $person);
            }

            $first = $logs->first();
            $last = $logs->last();

            $tail = "clocked in {$when} at " . $first->time_in->format('g:i A')
                . ($last->time_out
                    ? ', out ' . $last->time_out->format('g:i A') . '.'
                    : ($self ? ' and are still on duty.' : ' and is still on duty.'));

            return $this->answer('Attendance', [
                $this->row($this->say(true, true, $mid, $subject, $tail), 'high'),
            ], $person);
        }

        $logs = $this->logs($name, $from, $to);

        if ($logs->isEmpty()) {
            return $this->answer('Attendance', [
                $this->row("No attendance records found for {$mid}" . ($from ? ' ' . Str::lower($label) : '') . '.', 'medium'),
            ], $person);
        }

        $finished = $logs->filter(fn ($log) => $log->time_out !== null)->count();

        // "Ilang beses pumasok si Joshua?" is a number, nothing more.
        if ($this->isCount($q)) {
            return $this->answer('Attendance', [
                $this->row(
                    "{$subject} came in " . $this->countOf($logs->count(), 'time') . ' ' . $this->when($label, $from)
                        . ' (' . $this->countOf($finished, 'finished shift') . ', ' . $this->fmt($this->minutes($logs)) . ' worked).',
                    'high'
                ),
            ], $person);
        }

        $items = [
            $this->row(
                "{$subject} {$has} " . $this->countOf($finished, 'finished shift') . ' ' . $this->when($label, $from)
                    . ', ' . $this->fmt($this->minutes($logs)) . ' worked.',
                'high'
            ),
        ];

        $items = array_merge($items, $this->dutyNotes($logs));

        foreach ($logs->sortByDesc('time_in')->take(5) as $log) {
            $out = $log->time_out ? $log->time_out->format('g:i A') : 'no time out';

            $length = $log->time_out
                ? ' (' . $this->fmt((int) round($log->time_in->diffInMinutes($log->time_out, true))) . ')'
                : '';

            $items[] = $this->row($log->time_in->format('M j') . ': in ' . $log->time_in->format('g:i A') . ', out ' . $out . $length);
        }

        return $this->answer('Attendance', $items, $person);
    }

    // "Who is on duty?" or "Is anyone on duty?"
    private function onDuty(string $q): array
    {
        $yes = $this->isYesNo($q);

        $onDuty = AttendanceLog::whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->orderBy('time_in')
            ->get();

        if ($onDuty->isEmpty()) {
            return $this->answer('On duty', [
                $this->row($this->verdict($yes, false, 'nobody is on duty right now.', 'Nobody is on duty right now.')),
            ]);
        }

        $count = $onDuty->count();

        $items = [
            $this->row(
                $this->verdict($yes, true, $this->countOf($count, 'employee') . ($count === 1 ? ' is' : ' are') . ' on duty now.'),
                'high'
            ),
        ];

        if (!$this->isCount($q)) {
            foreach ($onDuty as $log) {
                $items[] = $this->row("{$log->employee_name}, since " . $log->time_in->format('g:i A'), 'medium');
            }
        }

        return $this->answer('On duty', $items, $count === 1 ? $onDuty->first()->employee_name : null);
    }

    // "Sino ang available?": not clocked in. The system has no schedule.
    public function availability(): array
    {
        $names = $this->employeeNames();

        if ($names->isEmpty()) {
            return $this->answer('Availability', [
                $this->row('There are no employees on file yet.', 'medium'),
            ]);
        }

        $busy = AttendanceLog::whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->pluck('employee_name')
            ->unique();

        $free = $names->reject(fn ($name) => $busy->contains($name))->values();

        if ($free->isEmpty()) {
            return $this->answer('Availability', [
                $this->row('Everyone is on duty right now.', 'medium'),
            ]);
        }

        $items = [
            $this->row($this->countOf($free->count(), 'employee') . ($free->count() === 1 ? ' is' : ' are') . ' not on duty right now.', 'high'),
        ];

        foreach ($free->take(10) as $name) {
            $items[] = $this->row($name);
        }

        $items[] = $this->row('The system has no shift schedule, so this only means they are not clocked in.');

        return $this->answer('Availability', $items);
    }

    // "Was Joshua late?": the system only has time in and time out, no schedule.
    public function schedule(string $q = '', bool $self = false): array
    {
        $person = null;

        if (!$self && $q !== '') {
            $matches = $this->findEmployees($q, $this->employeeNames());
            $person = $matches->count() === 1 ? $matches->first() : null;
        }

        return $this->answer('Attendance', [
            $this->row('The system has no shift schedule (it only records time in and time out), so it cannot tell who is late, absent, or on overtime.', 'medium'),
        ], $person);
    }

    /*
    |--------------------------------------------------------------------------
    | Data
    |--------------------------------------------------------------------------
    */

    // A recent open record means on duty now. An old one is a missing time out.
    public function dutyNotes(Collection $logs): array
    {
        $notes = [];

        $open = $logs
            ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
            ->last();

        if ($open) {
            $notes[] = $this->row('On duty now since ' . $open->time_in->format('M j, g:i A') . ' (not counted yet).', 'medium');
        }

        $stale = $logs->filter(fn ($log) => $log->time_out === null && $log->time_in < now()->subHours(24));

        if ($stale->isNotEmpty()) {
            $notes[] = $this->row(
                'Data note: ' . $this->countOf($stale->count(), 'record') . ' with no time out, not counted (for example the one from '
                    . $stale->first()->time_in->format('M j, g:i A') . ').',
                'medium'
            );
        }

        return $notes;
    }

    public function logs(?string $name, ?Carbon $from, ?Carbon $to): Collection
    {
        return AttendanceLog::query()
            ->when($name, fn ($query) => $query->where('employee_name', $name))
            ->when($from, fn ($query) => $query->where('time_in', '>=', $from->copy()->startOfDay()))
            ->when($to, fn ($query) => $query->where('time_in', '<=', $to->copy()->endOfDay()))
            ->orderBy('time_in')
            ->get();
    }

    // Only finished shifts count. An overnight shift works because
    // time_in and time_out are full date-times.
    public function minutes(Collection $logs): int
    {
        return (int) round(
            $logs
                ->filter(fn ($log) => $log->time_out !== null)
                ->sum(fn ($log) => $log->time_in->diffInMinutes($log->time_out, true))
        );
    }
}