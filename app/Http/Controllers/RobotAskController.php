<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use App\Models\Application;
use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Services\OllamaClient;
use App\Services\AI\AssistantContext;
use App\Services\AI\HRISAssistantService;
use App\Services\SystemKnowledge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RobotAskController extends Controller
{
    private const STATUS_LABELS = [
        'draft' => 'In progress',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'interview_scheduled' => 'Interview scheduled',
        'interview_completed' => 'Interview completed',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    // Specific on purpose, so a general chat question is not mistaken for data.
    private const HOURS_WORDS = [
        'worked', 'work hour', 'working hour', 'duty hour', 'total hour', 'how many hours',
        'hours of', 'hours ni', 'hours this', 'hours today', 'hours yesterday', 'my hours',
        'ilang oras', 'oras ni', 'oras ng', 'oras ang',
        'nagtrabaho', 'nag trabaho', 'nagwork', 'nag work',
    ];

    private const DOCUMENT_WORDS = [
        'document', 'dokumento', 'expire', 'expiry', 'expired', 'license', 'nbi', 'medical', 'renew',
    ];

    private const EMPLOYEE_WORDS = ['employee', 'empleyado', 'staff'];

    private const GREETING_WORDS = ['help', 'hi', 'hello', 'hey', 'kumusta', 'tulong'];

    // Common words that must never count as part of a person's name.
    private const NAME_STOP = [
        'the', 'and', 'for', 'who', 'how', 'many', 'any', 'are', 'there', 'our', 'has', 'have',
        'did', 'does', 'what', 'when', 'where', 'which', 'with', 'from', 'this', 'that', 'about',
        'tell', 'show', 'list', 'please', 'status', 'today', 'week', 'month', 'yesterday', 'tomorrow',
        'ito', 'yung', 'ang', 'mga', 'ng', 'sa', 'ni', 'kay', 'ba', 'na', 'po', 'ano', 'sino',
        'ilan', 'may', 'meron', 'mayroon', 'natin', 'namin', 'nila', 'siya', 'lahat', 'tungkol',
        'ngayon', 'kahapon', 'bukas', 'paki',
    ];

    /*
    |--------------------------------------------------------------------------
    | Entry point
    |--------------------------------------------------------------------------
    |
    | The AI services are NOT injected in the constructor on purpose. They are
    | only looked up when a question really needs them, so a problem in those
    | files can never take the instant rule-based answers down with it.
    |
    */

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'max:300'],
            'role' => ['required', 'in:HR,Employee,Applicant'],
            'employee_name' => ['nullable', 'string', 'max:255'],
        ]);

        $role = $data['role'];
        $text = trim($data['question']);

        try {
            $q = $this->normalize($text);
            $user = $this->userInfo($role, $data['employee_name'] ?? null);

            $explicitPerson = null;

            if ($role === 'HR') {
                $personMatches = $this->findEmployees($q, $this->employeeNames());

                if ($personMatches->count() === 1) {
                    $candidate = (string) $personMatches->first();
                    $normalizedCandidate = $this->normalize($candidate);

                    if (
                        $normalizedCandidate !== ''
                        && preg_match(
                            '/(?<![\pL\pN])'
                                . preg_quote($normalizedCandidate, '/')
                                . '(?![\pL\pN])/u',
                            $q
                        ) === 1
                    ) {
                        $explicitPerson = $candidate;
                    }
                }

                $isPronounFollowUp = preg_match(
                    '/\b(?:he|she|him|her|his|hers|they|them|their|theirs|siya|niya|nya|sya)\b/iu',
                    $text
                ) === 1;

                if ($explicitPerson === null && $isPronounFollowUp) {
                    $rememberedPerson = Cache::get(
                        $this->selectedPersonKey($request, $role)
                    );

                    if (
                        is_string($rememberedPerson)
                        && $rememberedPerson !== ''
                    ) {
                        $q = $this->normalize($q . ' ' . $rememberedPerson);
                    }
                }

                $normalizedText = $this->normalize($text);
                $isTemporalFollowUp = preg_match(
                    '/^(?:what about|how about|and|last week|this week|last month|this month|yesterday|today|kahapon|ngayon)\\b/iu',
                    $normalizedText
                ) === 1;

                if (
                    $explicitPerson === null
                    && !$isPronounFollowUp
                    && !$isTemporalFollowUp
                ) {
                    Cache::forget($this->selectedPersonKey($request, $role));
                }
            }

            // 1. Exact and instant: every number comes straight from the database.
            $answer = $this->byRules($role, $q, $user);

            if ($answer !== null) {
                if ($role === 'HR' && $explicitPerson !== null) {
                    Cache::put(
                        $this->selectedPersonKey($request, $role),
                        $explicitPerson,
                        now()->addHour()
                    );
                }

                $this->remember($request, $role, $text, '(showed ' . $answer['title'] . ')');

                return response()->json($answer);
            }

            // 2. Rules in this controller did not match. Ask the modular
            // assistant to interpret the question and let its rules answer.
            $context = AssistantContext::fromRequest(
                $request,
                $role,
                $user['name'] ?? null
            );

            $modular = app(HRISAssistantService::class)->askWithPath(
                $context,
                $text
            );

            $path = $modular['path'] ?? 'error';
            $unavailable = Str::startsWith($path, 'ai-unavailable:');
            $unhandled = in_array(
                $path,
                ['ai-off', 'ai-unknown', 'error'],
                true
            );

            // Preserve the old SystemKnowledge fallback when the modular
            // assistant cannot interpret the question or its router is offline.
            if ($unhandled || $unavailable) {
                return response()->json(
                    $this->bySmartMode($request, $role, $text, $user)
                );
            }

            $response = $modular['response'];

            // Keep the older session history usable if a later question falls
            // through to the legacy assistant.
            $firstItem = $response['items'][0] ?? [];
            $reply = is_array($firstItem)
                ? ($firstItem['text'] ?? $response['title'] ?? 'Assistant response')
                : ($response['title'] ?? 'Assistant response');

            $this->remember(
                $request,
                $role,
                $text,
                is_string($reply) ? $reply : 'Assistant response'
            );

            return response()->json($response);
        } catch (Throwable $e) {
            Log::error('Robot ask failed: ' . $e->getMessage(), ['exception' => $e]);

            return response()->json($this->failure($role, $e));
        }
    }

    // Shown inside the robot instead of a blank "could not answer".
    private function failure(string $role, Throwable $e): array
    {
        $items = [
            $this->row('Something went wrong on my side, so I could not answer that.', 'medium'),
        ];

        if (config('app.debug')) {
            $items[] = $this->row(
                class_basename($e) . ': ' . Str::limit($e->getMessage(), 280),
                'low',
                null,
                'Debug'
            );
        }

        return $this->answer('Sorry', array_merge($items, $this->help($role, '')['items']));
    }

    private function userInfo(string $role, ?string $employeeName): array
    {
        if ($role === 'Employee') {
            $name = $employeeName ?: 'Juan Dela Cruz';

            return ['name' => $name, 'line' => "{$name}, an employee"];
        }

        if ($role === 'Applicant') {
            $name = Auth::guard('applicant')->user()?->name;

            return [
                'name' => $name,
                'line' => $name ? "{$name}, a job applicant" : 'a job applicant who is not logged in',
            ];
        }

        return ['name' => null, 'line' => 'an HR staff member (name unknown)'];
    }

    private function byRules(string $role, string $q, array $user): ?array
    {
        if ($this->has($q, ['my name', 'who am i', 'sino ako', 'pangalan ko'])) {
            return $this->identity($role, $user['name']);
        }

        return match ($role) {
            'HR' => $this->askHr($q),
            'Employee' => $this->askEmployee($q, $user['name']),
            default => $this->askApplicant($q),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Smart mode: answer from the system data
    |--------------------------------------------------------------------------
    */

    private function bySmartMode(Request $request, string $role, string $text, array $user): array
    {
        if (!config('ollama.enabled')) {
            return $this->help($role, "Sorry, I didn't understand that");
        }

        // "php artisan serve" stops a request after 30 seconds by default.
        // The first question to the model can take longer than that.
        @set_time_limit((int) config('ollama.timeout') + 15);

        $ollama = app(OllamaClient::class);
        $service = app(SystemKnowledge::class);

        $history = $this->history($request, $role);
        $knowledge = $service->forRole($role, $user['name']);

        $result = $ollama->answer($text, $role, $user['line'], $knowledge['text'], $history);

        if ($result === null) {
            return $this->help($role, 'I could not think of an answer in time. Try again, or ask one of these');
        }

        $reply = $this->plain($result['answer']);

        if ($reply === '') {
            return $this->help($role, "Sorry, I didn't understand that");
        }

        // The exact lines the model says it used, taken from the database.
        $sources = [];

        foreach ($result['used'] as $id) {
            if (isset($knowledge['rows'][$id]) && count($sources) < 4) {
                $sources[] = $this->row($knowledge['rows'][$id], 'low', null, 'Source');
            }
        }

        // An answer about the system must not contain a number that is not in the data.
        // A "chat" answer to a question that sounds like HRIS gets the same check.
        $mustBeGrounded = $result['kind'] === 'data'
            || ($result['kind'] === 'chat' && $this->looksLikeHris($this->normalize($text)));

        if ($mustBeGrounded && !$this->isGrounded($reply, $text, $knowledge['text'])) {
            Log::info("Ollama grounding failed for \"{$text}\": {$reply}");

            $fallback = $this->help($role, "I'm not sure about that");

            array_unshift($fallback['items'], ...$sources);
            array_unshift(
                $fallback['items'],
                $this->row('I could not confirm that from the system data, so I will not guess.', 'medium', null, 'Notice')
            );

            return $fallback;
        }

        $this->remember($request, $role, $text, $reply);

        return match ($result['kind']) {
            'data' => $this->answer('Answer from system data', array_merge(
                [$this->row($reply, 'medium')],
                $sources
            )),
            'unknown' => $this->answer('Not found in the system', [$this->row($reply, 'medium')]),
            default => $this->answer('Assistant', [$this->row($reply, 'low')]),
        };
    }

    // Plain text only: no emoji, no markdown.
    private function plain(string $text): string
    {
        $text = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{26FF}\x{2705}\x{274C}\x{274E}\x{2728}\x{2753}\x{2757}\x{27A1}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{23E9}-\x{23F3}\x{FE0F}\x{200D}]+/u',
            '',
            $text
        );

        return trim(str_replace(['**', '__', '`'], '', (string) $text));
    }

    // Every number in the answer must appear in the data (or in the question).
    private function isGrounded(string $answer, string $question, string $data): bool
    {
        preg_match_all('/\d+(?:[.,]\d+)?/u', $answer, $found);

        $known = $data . ' ' . $question;

        foreach ($found[0] as $number) {
            if (!preg_match('/(?<!\d)' . preg_quote($number, '/') . '(?!\d)/u', $known)) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeHris(string $q): bool
    {
        return $this->has($q, [
            'applicant', 'application', 'aplikante', 'aplikasyon', 'apply', 'approved', 'rejected',
            'natanggap', 'nakapasa', 'pumasa', 'interview', 'status', 'hours', 'oras', 'duty',
            'shift', 'attendance', 'employee', 'empleyado', 'document', 'dokumento', 'expire',
            'license', 'schedule', 'remarks', 'submitted', 'pending',
        ]) || $this->hasWord($q, ['hr']);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversation memory (last 3 exchanges, kept per browser session)
    |--------------------------------------------------------------------------
    */

    private function historyKey(Request $request, string $role): string
    {
        $session = $request->hasSession() ? $request->session()->getId() : 'cli';

        return "robot-chat:{$session}:{$role}";
    }

    private function selectedPersonKey(Request $request, string $role): string
    {
        $session = $request->hasSession() ? $request->session()->getId() : 'cli';

        return "robot-selected-person:{$session}:{$role}";
    }

    private function history(Request $request, string $role): array
    {
        return Cache::get($this->historyKey($request, $role), []);
    }

    private function remember(Request $request, string $role, string $question, string $reply): void
    {
        $key = $this->historyKey($request, $role);

        $history = Cache::get($key, []);
        $history[] = ['role' => 'user', 'text' => Str::limit($question, 200, '')];
        $history[] = ['role' => 'assistant', 'text' => Str::limit($reply, 200, '')];

        Cache::put($key, array_slice($history, -6), now()->addHour());
    }

    /*
    |--------------------------------------------------------------------------
    | Rules: HR
    |--------------------------------------------------------------------------
    */

    private function askHr(string $q): ?array
    {
        if ($this->has($q, self::HOURS_WORDS)) {
            return $this->hrHours($q);
        }

        $attendanceAnswer = $this->hrAttendanceQuestion($q);

        if ($attendanceAnswer !== null) {
            return $attendanceAnswer;
        }

        if (
            $this->has($q, ['on duty', 'naka duty', 'nasa duty', 'pumasok', 'who is working', 'clocked in'])
            || $this->hasWord($q, ['present'])
        ) {
            return $this->onDuty();
        }

        if ($this->has($q, self::DOCUMENT_WORDS)) {
            return $this->hrDocuments($q);
        }

        // A request for one employee record must not fall through to the
        // general employee list when the requested person cannot be found.
        if ($this->has($q, ['employee record', 'employee profile'])) {
            $person = $this->hrPerson($q);

            if ($person !== null) {
                return $person;
            }

            return $this->answer('Person not found', [
                $this->row(
                    'I could not identify a matching employee or applicant. Please check the name.',
                    'medium'
                ),
            ]);
        }

        if ($this->has($q, self::EMPLOYEE_WORDS)) {
            return $this->hrEmployees($q);
        }

        // A person's name in the question: show everything about that person.
        $person = $this->hrPerson($q);

        if ($person !== null) {
            return $person;
        }

        if ($this->has($q, [
            'applicant', 'application', 'aplikante', 'aplikasyon', 'nag apply', 'nagapply',
            'nag aapply', 'nag submit', 'pending', 'approved', 'rejected', 'interview',
        ])) {
            return $this->hrApplicants($q);
        }

        if ($this->has($q, ['attention', 'overview', 'asikasuhin', 'ano ang bago', 'what s new'])) {
            return $this->hrSummary();
        }

        return $this->isGreeting($q) ? $this->help('HR', 'How can I help?') : null;
    }

    /**
     * Answers employee-specific attendance questions before the generic
     * on-duty handler gets a chance to intercept them.
     */
    private function hrAttendanceQuestion(string $q): ?array
    {
        $countTerms = [
            'ilang beses',
            'how many times',
            'attendance record',
            'attendance count',
            'count attendance',
            'ilang araw pumasok',
        ];

        $attendanceTerms = [
            'pumasok',
            'pasok',
            'attendance',
            'clock in',
            'clocked in',
            'time in',
            'came in',
            'check in',
            'on duty',
            'naka duty',
            'nasa duty',
            'currently working',
            'working right now',
        ];

        $lastTerms = [
            'huling',
            'last clock',
            'last time',
            'kailan huling',
            'most recent',
            'when did',
            'when was',
        ];

        $isCount = $this->has($q, $countTerms);
        $isLast = $this->has($q, $lastTerms);

        if (!$this->has($q, $attendanceTerms) && !$isCount) {
            return null;
        }

        $isCurrentDuty = $this->has($q, [
            'on duty',
            'naka duty',
            'nasa duty',
            'who is working',
            'clocked in',
            'currently working',
            'working right now',
        ]);

        $names = $this->employeeNames()
            ->merge(
                AttendanceLog::query()
                    ->whereNotNull('employee_name')
                    ->distinct()
                    ->pluck('employee_name')
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $matches = $this->findEmployees($q, $names);

        if ($matches->count() > 1) {
            return $this->answer('Which employee?', [
                $this->row(
                    'I found more than one employee match: '
                    . $matches->implode(', ')
                    . '. Please use the full name.',
                    'medium'
                ),
            ]);
        }

        if ($matches->count() === 1) {
            $name = $matches->first();

            if (
                $isCurrentDuty
                && !$isLast
                && !$isCount
                && !$this->has($q, ['today', 'ngayon', 'ngayong araw', 'yesterday', 'kahapon'])
            ) {
                $openShift = $this->attendanceQueryForEmployee($name)
                    ->whereNull('time_out')
                    ->where('time_in', '>=', now()->subHours(24))
                    ->latest('time_in')
                    ->first();

                if ($openShift) {
                    return $this->answer('Current duty status', [
                        $this->row(
                            "{$name} is currently on duty since "
                            . $openShift->time_in->format('M j, Y g:i A')
                            . '.',
                            'medium',
                            null,
                            'On duty'
                        ),
                    ]);
                }

                return $this->answer('Current duty status', [
                    $this->row(
                        "{$name} is not currently recorded as on duty.",
                        'low'
                    ),
                ]);
            }

            if ($isCount) {
                [$label, $from, $to] = $this->range($q);

                $query = $this->attendanceQueryForEmployee($name);

                if ($from) {
                    $query->where(
                        'time_in',
                        '>=',
                        $from->copy()->startOfDay()
                    );
                }

                if ($to) {
                    $query->where(
                        'time_in',
                        '<=',
                        $to->copy()->endOfDay()
                    );
                }

                $count = $query->count();
                $period = $from ? Str::lower($label) : 'in total';

                return $this->answer('Attendance count', [
                    $this->row(
                        "{$name} has {$count} attendance record(s) {$period}.",
                        'medium',
                        null,
                        'Attendance'
                    ),
                ]);
            }

            if ($isLast) {
                $latest = $this->attendanceQueryForEmployee($name)
                    ->latest('time_in')
                    ->first();

                if (!$latest) {
                    return $this->answer('Attendance history', [
                        $this->row(
                            "No attendance records were found for {$name}.",
                            'medium'
                        ),
                    ]);
                }

                return $this->answer('Last clock-in', [
                    $this->row(
                        'Last clock-in for ' . $name . ': '
                        . $latest->time_in->format('M j, Y g:i A')
                        . '.',
                        'medium',
                        null,
                        'Attendance'
                    ),
                ]);
            }

            $isYesterday = $this->has($q, ['yesterday', 'kahapon']);
            $asksDailyAttendance = $this->has(
                $q,
                ['today', 'ngayon', 'ngayong araw', 'yesterday', 'kahapon']
            ) || $this->hasWord($q, ['ba', 'did', 'was', 'has', 'have']);

            if ($asksDailyAttendance) {
                $day = $isYesterday ? today()->subDay() : today();
                $period = $isYesterday ? 'yesterday' : 'today';

                $records = $this->attendanceQueryForEmployee($name)
                    ->whereDate('time_in', $day->toDateString())
                    ->orderBy('time_in')
                    ->get();

                if ($records->isEmpty()) {
                    return $this->answer('Attendance today', [
                        $this->row(
                            "{$name} has no attendance record for {$period}.",
                            'low',
                            null,
                            'Attendance'
                        ),
                    ]);
                }

                $first = $records->first();

                return $this->answer('Attendance today', [
                    $this->row(
                        "{$name} clocked in {$period} at "
                        . $first->time_in->format('g:i A')
                        . '.',
                        'medium',
                        null,
                        'Attendance'
                    ),
                    $this->row(
                        'Attendance record(s) for '
                        . $period
                        . ': '
                        . $records->count()
                        . '.',
                        'low'
                    ),
                ]);
            }

            $latest = $this->attendanceQueryForEmployee($name)
                ->latest('time_in')
                ->first();

            if (!$latest) {
                return $this->answer('Attendance history', [
                    $this->row(
                        "No attendance records were found for {$name}.",
                        'medium'
                    ),
                ]);
            }

            return $this->answer('Latest attendance', [
                $this->row(
                    'Latest attendance record for ' . $name . ': '
                    . $latest->time_in->format('M j, Y g:i A')
                    . '.',
                    'medium'
                ),
            ]);
        }

        // If a specific name was mentioned but is not in our records,
        // do not silently answer with a general on-duty result.
        if (preg_match(
            '/\b(?:si|ni|kay|of|for)\s+([\p{L}]+)/u',
            $q,
            $mentioned
        )) {
            $askedName = $mentioned[1];

            return $this->answer('Employee not found', [
                $this->row(
                    "No matching employee attendance record was found for "
                    . $askedName
                    . '.',
                    'medium'
                ),
            ]);
        }

        // Preserve the existing current-duty answer for general questions.
        if ($isCurrentDuty) {
            return null;
        }

        if ($isCount) {
            [$label, $from, $to] = $this->range($q);

            $query = AttendanceLog::query();

            if ($from) {
                $query->where(
                    'time_in',
                    '>=',
                    $from->copy()->startOfDay()
                );
            }

            if ($to) {
                $query->where(
                    'time_in',
                    '<=',
                    $to->copy()->endOfDay()
                );
            }

            $period = $from ? Str::lower($label) : 'in total';

            return $this->answer('Attendance count', [
                $this->row(
                    $query->count()
                    . " attendance record(s) {$period}.",
                    'medium'
                ),
            ]);
        }

        if ($isLast) {
            return $this->answer('Attendance history', [
                $this->row(
                    'Please include the employee name so I can find their last clock-in.',
                    'medium'
                ),
            ]);
        }

        $isYesterday = $this->has($q, ['yesterday', 'kahapon']);
        $day = $isYesterday ? today()->subDay() : today();
        $period = $isYesterday ? 'yesterday' : 'today';

        $records = AttendanceLog::query()
            ->whereDate('time_in', $day->toDateString())
            ->orderBy('time_in')
            ->get();

        if ($records->isEmpty()) {
            return $this->answer('Attendance today', [
                $this->row(
                    "No attendance records were found for {$period}.",
                    'low'
                ),
            ]);
        }

        $items = $records
            ->take(6)
            ->map(fn ($record) => $this->row(
                $record->employee_name
                . ' clocked in '
                . $period
                . ' at '
                . $record->time_in->format('g:i A')
                . '.',
                'low',
                null,
                'Attendance'
            ))
            ->values()
            ->all();

        if ($records->count() > 6) {
            $items[] = $this->row(
                '+' . ($records->count() - 6) . ' more attendance record(s).',
                'low'
            );
        }

        return $this->answer('Attendance today', $items);
    }

    private function hrHours(string $q): array
    {
        [$label, $from, $to] = $this->range($q);

        // Include official employee names for ID-linked attendance,
        // and legacy names only for attendance rows without an employee ID.
        $names = Employee::query()
            ->whereHas('attendanceLogs')
            ->pluck('name')
            ->merge(
                AttendanceLog::query()
                    ->whereNull('employee_id')
                    ->whereNotNull('employee_name')
                    ->distinct()
                    ->orderBy('employee_name')
                    ->pluck('employee_name')
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        if ($names->isEmpty()) {
            return $this->answer('Worked hours', [
                $this->row('There are no attendance records yet.', 'medium'),
            ]);
        }

        $matches = $this->findEmployees($q, $names);

        if ($matches->count() > 1) {
            return $this->answer('Which employee?', [
                $this->row('I found more than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Please use the full name, for example "hours of ' . $matches->first() . '".'),
            ]);
        }

        if ($matches->count() === 1) {
            return $this->hoursFor($matches->first(), $label, $from, $to);
        }

        // A name was typed but nobody matches it.
        $asked = $this->mentionedName($q);

        if ($asked) {
            return $this->answer("I can't find \"{$asked}\"", [
                $this->row('Employees with attendance records: ' . $names->implode(', ') . '.', 'medium'),
                $this->row('Try: "Total worked hours ni ' . Str::before($names->first(), ' ') . '".'),
            ]);
        }

        // No name at all: show everyone.
        $allLogs = $this->logs(null, $from, $to);

        $linkedEmployeeNames = Employee::query()
            ->whereIn(
                'id',
                $allLogs->pluck('employee_id')->filter()->unique()->values()
            )
            ->pluck('name', 'id');

        $byName = $allLogs
            ->groupBy(function ($log) use ($linkedEmployeeNames) {
                if ($log->employee_id !== null) {
                    return $linkedEmployeeNames->get($log->employee_id)
                        ?? $log->employee_name;
                }

                return $log->employee_name;
            })
            ->map(fn (Collection $group) => $this->minutes($group))
            ->sortDesc();

        if ($byName->isEmpty()) {
            return $this->answer("Worked hours – {$label}", [
                $this->row('No attendance records for ' . Str::lower($label) . '.', 'medium'),
            ]);
        }

        $items = $byName
            ->take(6)
            ->map(fn ($minutes, $name) => $this->row("{$name}: " . $this->fmt((int) $minutes), 'medium', null, 'Hours'))
            ->values()
            ->all();

        $items[] = $this->row('Everyone together: ' . $this->fmt((int) $byName->sum()), 'low', null, 'Total');

        return $this->answer("Worked hours – {$label}", $items);
    }

    private function onDuty(): array
    {
        $onDuty = AttendanceLog::query()
            ->whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->orderBy('time_in')
            ->get();

        $linkedEmployeeNames = Employee::query()
            ->whereIn(
                'id',
                $onDuty->pluck('employee_id')->filter()->unique()->values()
            )
            ->pluck('name', 'id');

        $todayLogs = AttendanceLog::query()
            ->whereDate('time_in', today())
            ->get(['employee_id', 'employee_name']);

        $employeeIdsByName = Employee::query()
            ->get(['id', 'name'])
            ->mapWithKeys(fn (Employee $employee) => [
                $this->normalize($employee->name) => $employee->id,
            ]);

        // Count linked rows by employee ID. For legacy rows, use the linked
        // employee ID when the name matches; otherwise count the legacy name.
        $clockedToday = $todayLogs
            ->map(function ($log) use ($employeeIdsByName) {
                if ($log->employee_id !== null) {
                    return 'employee:' . $log->employee_id;
                }

                $legacyName = $this->normalize(
                    (string) $log->employee_name
                );

                $linkedId = $employeeIdsByName->get($legacyName);

                if ($linkedId !== null) {
                    return 'employee:' . $linkedId;
                }

                return $legacyName !== ''
                    ? 'legacy:' . $legacyName
                    : null;
            })
            ->filter()
            ->unique()
            ->count();

        $items = [];

        if ($onDuty->isEmpty()) {
            $items[] = $this->row(
                'Nobody is clocked in right now.',
                'low'
            );
        } else {
            foreach ($onDuty as $log) {
                $displayName = $log->employee_id !== null
                    ? $linkedEmployeeNames->get($log->employee_id)
                    : null;

                $displayName = $displayName
                    ?: $log->employee_name
                    ?: 'Unknown employee';

                $items[] = $this->row(
                    "{$displayName}, since " . $log->time_in->format('g:i A'),
                    'medium',
                    null,
                    'On duty'
                );
            }
        }

        $items[] = $this->row(
            "{$clockedToday} employee(s) clocked in today.",
            'low'
        );

        return $this->answer('On duty now', $items);
    }
    private function hrDocuments(string $q): array
    {
        // Include employee records as well as legacy attendance/document names.
        // This lets HR ask about employees who do not have documents yet.
        $names = Employee::query()
            ->pluck('name')
            ->merge($this->employeeNames())
            ->merge(
                EmployeeDocument::query()
                    ->whereNotNull('employee_name')
                    ->distinct()
                    ->pluck('employee_name')
            )
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $matches = $this->findEmployees($q, $names);

        if ($matches->count() > 1) {
            return $this->answer('Which employee?', [
                $this->row(
                    'I found more than one employee match: '
                    . $matches->implode(', ')
                    . '. Please use the full name.',
                    'medium'
                ),
            ]);
        }

        if ($matches->count() === 1) {
            $name = $matches->first();

            return $this->employeeDocuments(
                $name,
                "Documents – {$name}"
            );
        }

        // No employee was identified: retain the general compliance summary.
        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())
            ->orderBy('expiry_date')
            ->get();

        $soon = EmployeeDocument::whereBetween(
            'expiry_date',
            [today(), today()->copy()->addDays(30)]
        )
            ->orderBy('expiry_date')
            ->get();

        $items = [];

        if ($expired->isNotEmpty()) {
            $items[] = $this->row(
                "{$expired->count()} expired document(s)",
                'critical',
                route('hr.compliance')
            );

            foreach ($expired->take(3) as $document) {
                $days = (int) $document->expiry_date->diffInDays(today(), true);

                $items[] = $this->row(
                    "{$document->employee_name}, {$document->document_type} ({$days} day(s) overdue)",
                    'high',
                    null,
                    'Expired'
                );
            }
        }

        if ($soon->isNotEmpty()) {
            $items[] = $this->row(
                "{$soon->count()} expiring within 30 days",
                'high'
            );

            foreach ($soon->take(3) as $document) {
                $days = (int) today()->diffInDays($document->expiry_date, true);

                $items[] = $this->row(
                    "{$document->employee_name}, {$document->document_type} ({$days} day(s) left)",
                    'medium',
                    null,
                    'Expiring'
                );
            }
        }

        if (!$items) {
            $items[] = $this->row(
                'All employee documents are valid.',
                'low'
            );
        }

        return $this->answer('Document status', $items);
    }

    private function employeeNames(): Collection
    {
        return Employee::query()->pluck('name')
            ->merge(AttendanceLog::query()->distinct()->pluck('employee_name'))
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    // "How many employees do we have?" or "Is Joshua Balde an employee?"
    private function hrEmployees(string $q): array
    {
        $names = $this->employeeNames();

        if ($names->isEmpty()) {
            return $this->answer('Employees', [
                $this->row('There are no employee profiles, attendance records, or employee documents on file yet.', 'medium'),
            ]);
        }

        $people = $names->merge(Applicant::query()->pluck('name'))->unique()->sort()->values();
        $matches = $this->findEmployees($q, $people);

        if ($matches->count() === 1) {
            $person = $matches->first();
            $profile = $this->personProfile($person);

            array_unshift(
                $profile['items'],
                $names->contains($person)
                    ? $this->row('Listed in the employee records or associated attendance/document records.', 'medium', null, 'Employee')
                    : $this->row('Not found in the employee profiles, attendance records, or employee documents.', 'medium', null, 'Employee')
            );

            return $profile;
        }

        $logs = AttendanceLog::whereIn('employee_name', $names)
            ->orderBy('time_in')
            ->get()
            ->groupBy('employee_name');

        $items = [
            $this->row("{$names->count()} person(s) on file (employee profiles, attendance records, or documents).", 'high'),
        ];

        foreach ($names->take(10) as $name) {
            $own = $logs->get($name, collect());

            $open = $own
                ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
                ->last();

            $items[] = $this->row(
                "{$name}: total " . $this->fmt($this->minutes($own)) . ($open ? ', on duty now' : ''),
                'low',
                null,
                'Employee'
            );
        }

        if ($names->count() > 10) {
            $items[] = $this->row('+' . ($names->count() - 10) . ' more', 'low');
        }

        return $this->answer('Employees', $items);
    }

    /**
     * Extract a person's name from common, specific HR lookup questions.
     * The input has already been normalized, so possessive apostrophes appear
     * as a standalone "s" token (for example, "Joshua Santos s position").
     */
    private function requestedPersonName(string $q): ?string
    {
        $normalized = $this->normalize($q);

        // Remove the normalized possessive token only when it directly
        // precedes one of the supported question targets.
        $normalized = preg_replace(
            '/\s+s(?=\s+(?:application\s+status|employment\s+status|employee\s+(?:record|profile)|position|role|active|hired|employed)\b)/u',
            '',
            $normalized
        );

        $patterns = [
            '/^(?:show me|find|open|view|display)\s+(.+?)\s+(?:employee\s+)?(?:record|profile)\b/u',
            '/^(?:what is|what s|tell me about|tell me|show me|check)\s+(.+?)\s+(?:application\s+status|employment\s+status|position|role)\b/u',
            '/^is\s+(.+?)\s+(?:currently\s+)?active\b/u',
            '/^when was\s+(.+?)\s+(?:hired|employed)\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches) === 1) {
                $name = trim($matches[1]);

                if ($name !== '') {
                    return Str::title($name);
                }
            }
        }

        return null;
    }

    // A person (applicant or employee) is named in the question.
    private function hrPerson(string $q): ?array
    {
        $people = $this->employeeNames()
            ->merge(Applicant::query()->pluck('name'))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $requestedName = $this->requestedPersonName($q);

               if ($requestedName !== null) {
            $normalizedRequestedName = $this->normalize($requestedName);

            $exactName = $people->first(
                fn ($name) => $this->normalize((string) $name)
                    === $normalizedRequestedName
            );

            // A complete exact name always takes precedence.
            if ($exactName !== null) {
                return $this->personProfile((string) $exactName);
            }

            // A single first name can match when the result is unambiguous.
            // Never use this fallback for a supplied full name.
            $requestedTokenCount = collect(
                explode(' ', $normalizedRequestedName)
            )->filter(fn ($token) => $token !== '')->count();

            if ($requestedTokenCount === 1) {
                $partialMatches = $this->findEmployees(
                    $normalizedRequestedName,
                    $people
                );

                if ($partialMatches->count() === 1) {
                    return $this->personProfile(
                        (string) $partialMatches->first()
                    );
                }

                if ($partialMatches->count() > 1) {
                    return $this->answer('Which person?', [
                        $this->row(
                            'More than one match: '
                                . $partialMatches->implode(', ') . '.',
                            'medium'
                        ),
                        $this->row(
                            'Please use the full name.',
                            'low'
                        ),
                    ]);
                }
            }

            // Unknown full names must not fall back to another person.
            return $this->answer('Person not found', [
                $this->row(
                    "No employee or applicant record was found for {$requestedName}.",
                    'medium'
                ),
            ]);
        }
        $matches = $this->findEmployees($q, $people);

        if ($matches->count() === 1) {
            return $this->personProfile($matches->first());
        }

        if ($matches->count() > 1) {
            return $this->answer('Which person?', [
                $this->row('More than one match: ' . $matches->implode(', ') . '.', 'medium'),
                $this->row('Please use the full name.', 'low'),
            ]);
        }

        return null;
    }

    // Everything the system knows about one person.
    private function personProfile(string $name): array
    {
        $items = [];

        $employee = Employee::query()
            ->where('name', $name)
            ->first();

        if ($employee) {
            $position = $employee->position ?: 'not recorded';
            $employmentStatus = $employee->employment_status ?: 'not recorded';
            $dateHired = $employee->date_hired
                ? $employee->date_hired->format('M j, Y')
                : 'not recorded';

            $items[] = $this->row(
                "Position: {$position}; employment status: {$employmentStatus}; date hired: {$dateHired}.",
                'medium',
                null,
                'Employee profile'
            );
        }

        $applications = Application::with('applicant')
            ->whereHas('applicant', fn ($query) => $query->where('name', $name))
            ->latest('id')
            ->take(3)
            ->get();

        foreach ($applications as $application) {
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;
            $parts = ["{$application->job_title}, status {$status}"];

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

            $items[] = $this->row(
                implode('; ', $parts) . '.',
                'low',
                $application->status === 'draft' ? null : route('hr.applicants.show', $application),
                'Application'
            );
        }

        $logs = $this->attendanceQueryForEmployee($name)->orderBy('time_in')->get();

        if ($logs->isNotEmpty()) {
            $week = $this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek()));
            $month = $this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth()));

            $open = $logs
                ->filter(fn ($log) => $log->time_out === null && $log->time_in >= now()->subHours(24))
                ->last();

            $items[] = $this->row(
                'Worked hours: this week ' . $this->fmt($week)
                    . '; this month ' . $this->fmt($month)
                    . '; all time ' . $this->fmt($this->minutes($logs))
                    . ($open ? '; on duty now since ' . $open->time_in->format('g:i A') : '; not on duty now') . '.',
                $open ? 'medium' : 'low',
                null,
                'Attendance'
            );
        }

        $documents = $this->documentQueryForEmployee($name)->orderBy('expiry_date')->get();

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $state = "expired {$days} day(s) ago";
                $level = 'critical';
            } elseif ($document->expiry_date->lte(today()->addDays(30))) {
                $state = "{$days} day(s) left";
                $level = 'high';
            } else {
                $state = 'valid';
                $level = 'low';
            }

            $items[] = $this->row(
                "{$document->document_type}, expires " . $document->expiry_date->format('M j, Y') . " ({$state}).",
                $level,
                null,
                'Document'
            );
        }

        if (!$items) {
            $items[] = $this->row("No records found for {$name}.", 'medium');
        }

        return $this->answer($name, $items);
    }

    private function hrApplicants(string $q): array
    {
        [$label, $from, $to] = $this->range($q);

        // Applicants who have not submitted yet.
        if ($this->has($q, ['in progress', 'draft', 'filling', 'incomplete'])) {
            $count = Application::where('status', 'draft')->count();

            return $this->answer('Applications in progress', [
                $this->row(
                    "{$count} applicant(s) are still filling out their application.",
                    $count > 0 ? 'medium' : 'low'
                ),
            ]);
        }

        $statuses = null;
        $what = 'submitted';

        if ($this->has($q, ['approved', 'approve'])) {
            $statuses = ['approved'];
            $what = 'approved';
        } elseif ($this->has($q, ['rejected', 'reject', 'denied'])) {
            $statuses = ['rejected'];
            $what = 'rejected';
        } elseif ($this->has($q, ['interview'])) {
            $statuses = ['interview_scheduled', 'interview_completed'];
            $what = 'in the interview stage';
        } elseif ($this->has($q, ['pending', 'review', 'waiting', 'hintay', 'naghihintay'])) {
            $statuses = ['submitted', 'under_review'];
            $what = 'waiting for review';
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
        $when = $from ? Str::lower($label) : 'in total';

        if ($interviewStage && $from) {
            $when .= ' (by interview date)';
        }

        $isPending = $statuses === ['submitted', 'under_review'];

        $items = [
            $this->row(
                "{$count} application(s) {$what} – {$when}",
                $count === 0 ? 'low' : ($isPending ? 'high' : 'medium'),
                $count > 0 ? route('hr.applicants') : null
            ),
        ];

        // Breakdown by status, only when the question was not about one status.
        if ($statuses === null && $count > 0) {
            $parts = $applications
                ->groupBy('status')
                ->map(fn (Collection $group, string $status) => (self::STATUS_LABELS[$status] ?? $status) . ': ' . $group->count())
                ->values()
                ->implode(' · ');

            $items[] = $this->row($parts, 'low', null, 'By status');
        }

        foreach ($applications->take(5) as $application) {
            $name = $application->applicant->name ?? 'Unknown';
            $status = self::STATUS_LABELS[$application->status] ?? $application->status;

            $line = "{$name}, {$application->job_title} ({$status})";

            if ($interviewStage && $application->interview_at) {
                $line .= ', interview ' . Carbon::parse($application->interview_at)->format('M j, g:i A')
                    . ($application->interview_location ? ' at ' . $application->interview_location : '');
            }

            $items[] = $this->row($line, 'low', route('hr.applicants.show', $application), 'Applicant');
        }

        if ($count > 5) {
            $items[] = $this->row('+' . ($count - 5) . ' more', 'low');
        }

        return $this->answer("Applicants – {$label}", $items);
    }

    // "What needs my attention?": everything HR still has to act on.
    private function hrSummary(): array
    {
        $counts = Application::query()
            ->whereIn('status', ['submitted', 'under_review', 'interview_scheduled', 'interview_completed'])
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $new = (int) ($counts['submitted'] ?? 0);
        $underReview = (int) ($counts['under_review'] ?? 0);
        $scheduled = (int) ($counts['interview_scheduled'] ?? 0);
        $interviewed = (int) ($counts['interview_completed'] ?? 0);

        $expired = EmployeeDocument::whereDate('expiry_date', '<', today())->count();

        $soon = EmployeeDocument::whereBetween('expiry_date', [today(), today()->addDays(30)])->count();

        $onDuty = AttendanceLog::whereNull('time_out')
            ->where('time_in', '>=', now()->subHours(24))
            ->count();

        $items = [];

        if ($interviewed > 0) {
            $items[] = $this->row("{$interviewed} interview(s) done, ready for your decision", 'high', route('hr.applicants'));
        }

        if ($new > 0) {
            $items[] = $this->row("{$new} new application(s) to review", 'high', route('hr.applicants'));
        }

        if ($underReview > 0) {
            $items[] = $this->row("{$underReview} under review, interview still to be scheduled", 'medium', route('hr.applicants'));
        }

        if ($scheduled > 0) {
            $items[] = $this->row("{$scheduled} interview(s) scheduled", 'medium', route('hr.applicants'));
        }

        if ($expired > 0) {
            $items[] = $this->row("{$expired} expired employee document(s)", 'critical', route('hr.compliance'));
        }

        if ($soon > 0) {
            $items[] = $this->row("{$soon} document(s) expiring within 30 days", 'high', route('hr.compliance'));
        }

        if (!$items) {
            $items[] = $this->row('Nothing needs your attention right now.', 'low');
        }

        $items[] = $this->row("{$onDuty} employee(s) on duty now.", 'low');

        return $this->answer('What needs your attention', $items);
    }

    private function help(string $role, string $title): array
    {
        $items = match ($role) {
            'HR' => [
                $this->row('"How many applicants today?", "Pending applications", "Who has an interview this week?"', 'low', null, 'Applicants'),
                $this->row('"Status ni kym mendroga", "How many employees do we have?"', 'low', null, 'People'),
                $this->row('"Total worked hours ni Joshua", "Hours of Juan this week"', 'low', null, 'Hours'),
                $this->row('"Who is on duty now?"', 'low', null, 'Attendance'),
                $this->row('"Any expired documents?", "Documents ni Juan"', 'low', null, 'Documents'),
                $this->row('"What needs my attention?"', 'low', null, 'Overview'),
            ],
            'Employee' => [
                $this->row('"My worked hours this week", "Total worked hours"', 'low', null, 'Hours'),
                $this->row('"Which documents expire soon?"', 'low', null, 'Documents'),
            ],
            default => [
                $this->row('"What is my application status?"', 'low', null, 'Status'),
                $this->row('"What is my next step?"', 'low', null, 'Next step'),
                $this->row('"When is my interview?"', 'low', null, 'Interview'),
            ],
        };

        if (config('ollama.enabled')) {
            $items[] = $this->row('You can also ask me anything about the data in the system, in English or Filipino.', 'low', null, 'Tip');
        }

        return $this->answer($title, $items);
    }

    private function identity(string $role, ?string $name): array
    {
        if ($role === 'Applicant' && !$name) {
            return $this->answer('Who you are', [
                $this->row('Log in and I can tell you which account you are using.', 'medium'),
            ]);
        }

        if ($name) {
            $kind = $role === 'Employee' ? 'an employee' : 'a job applicant';

            return $this->answer('Who you are', [
                $this->row("You are {$name}, {$kind} at Frontier Security Services Inc.", 'low'),
            ]);
        }

        return $this->answer('Who you are', [
            $this->row('You are using the HR portal. I do not know your name because HR has no login in this demo yet.', 'low'),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Rules: Employee
    |--------------------------------------------------------------------------
    */

    private function askEmployee(string $q, string $name): ?array
    {
        if ($this->has($q, self::HOURS_WORDS)) {
            [$label, $from, $to] = $this->range($q);

            return $this->hoursFor($name, $label, $from, $to, 'My worked hours');
        }

        if ($this->has($q, self::DOCUMENT_WORDS)) {
            return $this->employeeDocuments($name);
        }

        return $this->isGreeting($q) ? $this->help('Employee', 'How can I help?') : null;
    }

    private function employeeDocuments(
        string $name,
        string $title = 'My documents'
    ): array {
        $documents = $this->documentQueryForEmployee($name)
            ->orderBy('expiry_date')
            ->get();

        if ($documents->isEmpty()) {
            return $this->answer($title, [
                $this->row('No documents on file yet.', 'medium'),
            ]);
        }

        $items = [];

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row(
                    "{$document->document_type}, expired {$days} day(s) ago",
                    'critical',
                    null,
                    'Expired'
                );
            } elseif ($document->expiry_date->lte(today()->copy()->addDays(30))) {
                $items[] = $this->row(
                    "{$document->document_type}, {$days} day(s) left",
                    'high',
                    null,
                    'Expiring'
                );
            } else {
                $items[] = $this->row(
                    "{$document->document_type}, valid until "
                    . $document->expiry_date->format('M j, Y'),
                    'low',
                    null,
                    'Valid'
                );
            }
        }

        return $this->answer($title, $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Rules: Applicant (always the logged-in applicant, never an id from the request)
    |--------------------------------------------------------------------------
    */

    private function askApplicant(string $q): ?array
    {
        $intent = match (true) {
            $this->has($q, ['interview', 'schedule', 'iskedyul']) => 'interview',
            $this->has($q, ['next', 'susunod', 'what do i do', 'gagawin']) => 'next',
            $this->has($q, ['status', 'application', 'where is my', 'nasaan', 'any update', 'result']) => 'status',
            default => null,
        };

        if ($intent === null) {
            return $this->isGreeting($q) ? $this->help('Applicant', 'How can I help?') : null;
        }

        $applicant = Auth::guard('applicant')->user();

        if (!$applicant) {
            return $this->answer('Please log in', [
                $this->row('Log in to ask about your application.', 'medium'),
            ]);
        }

        $application = $applicant->applications()->latest('id')->first();

        if (!$application) {
            return $this->answer('No application yet', [
                $this->row('You have not started an application yet.', 'medium'),
                $this->row('Click "Start Application" on your dashboard to begin.', 'medium'),
            ]);
        }

        $label = self::STATUS_LABELS[$application->status] ?? ucfirst($application->status);

        if ($intent === 'interview') {
            if ($application->interview_at) {
                $when = Carbon::parse($application->interview_at);

                $items = [
                    $this->row('Your interview: ' . $when->format('l, M j, Y \a\t g:i A'), 'high'),
                ];

                if ($application->interview_location) {
                    $items[] = $this->row("Location: {$application->interview_location}", 'medium');
                }

                return $this->answer('Your interview', $items);
            }

            return $this->answer('Your interview', [
                $this->row('No interview is scheduled yet.', 'medium'),
                $this->row($this->nextStep($application), 'low', null, 'Next step'),
            ]);
        }

        if ($intent === 'next') {
            return $this->answer('Your next step', [
                $this->row($this->nextStep($application), 'high'),
            ]);
        }

        $level = in_array($application->status, ['approved', 'rejected'], true) ? 'high' : 'medium';

        $items = [
            $this->row("{$application->job_title}: {$label}", $level, null, 'Status'),
        ];

        if ($application->remarks) {
            $items[] = $this->row($application->remarks, 'medium', null, 'HR remarks');
        }

        $items[] = $this->row($this->nextStep($application), 'low', null, 'Next step');

        return $this->answer('Your application', $items);
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

    /*
    |--------------------------------------------------------------------------
    | Worked hours
    |--------------------------------------------------------------------------
    */

    private function hoursFor(
        string $name,
        string $label,
        ?Carbon $from,
        ?Carbon $to,
        ?string $title = null
    ): array {
        $title ??= "{$name} – worked hours";

        $logs = $this->logs($name, $from, $to);
        $closed = $logs->filter(fn ($log) => $log->time_out !== null);
        $open = $logs->filter(fn ($log) => $log->time_out === null);

        $items = [];

        if ($logs->isEmpty()) {
            $items[] = $this->row('No attendance records for ' . Str::lower($label) . '.', 'medium');
        } else {
            $when = $from ? $label : 'Total (all time)';

            $items[] = $this->row(
                "{$when}: " . $this->fmt($this->minutes($closed)) . " in {$closed->count()} shift(s)",
                'high'
            );
        }

        if ($from === null) {
            $month = $this->minutes($this->logs($name, today()->startOfMonth(), today()->endOfMonth()));
            $week = $this->minutes($this->logs($name, today()->startOfWeek(), today()->endOfWeek()));

            $items[] = $this->row('This month: ' . $this->fmt($month), 'low');
            $items[] = $this->row('This week: ' . $this->fmt($week), 'low');
        } else {
            $all = $this->minutes($this->logs($name, null, null));

            $items[] = $this->row('Total (all time): ' . $this->fmt($all), 'low');
        }

        if ($open->isNotEmpty()) {
            $items[] = $this->row(
                'Currently on duty since ' . $open->last()->time_in->format('M j, g:i A') . ' (not counted yet)',
                'medium',
                null,
                'On duty'
            );
        }

        return $this->answer($title, $items);
    }

    /**
     * Prefer an employee's stable ID. Only use employee_name as a fallback
     * for legacy rows that have no employee_id.
     */
    private function attendanceQueryForEmployee(string $name)
    {
        $employee = Employee::query()
            ->where('name', $name)
            ->first();

        return AttendanceLog::query()->where(function ($query) use ($employee, $name) {
            if ($employee) {
                $query->where('employee_id', $employee->id)
                    ->orWhere(function ($legacyQuery) use ($name) {
                        $legacyQuery
                            ->whereNull('employee_id')
                            ->where('employee_name', $name);
                    });
            } else {
                $query->whereNull('employee_id')
                    ->where('employee_name', $name);
            }
        });
    }

    /**
     * Use the same identity rule for documents as attendance logs.
     */
    private function documentQueryForEmployee(string $name)
    {
        $employee = Employee::query()
            ->where('name', $name)
            ->first();

        return EmployeeDocument::query()->where(function ($query) use ($employee, $name) {
            if ($employee) {
                $query->where('employee_id', $employee->id)
                    ->orWhere(function ($legacyQuery) use ($name) {
                        $legacyQuery
                            ->whereNull('employee_id')
                            ->where('employee_name', $name);
                    });
            } else {
                $query->whereNull('employee_id')
                    ->where('employee_name', $name);
            }
        });
    }

    private function logs(?string $name, ?Carbon $from, ?Carbon $to): Collection
    {
        $query = $name !== null
            ? $this->attendanceQueryForEmployee($name)
            : AttendanceLog::query();

        return $query
            ->when($from, fn ($query) => $query->where(
                'time_in',
                '>=',
                $from->copy()->startOfDay()
            ))
            ->when($to, fn ($query) => $query->where(
                'time_in',
                '<=',
                $to->copy()->endOfDay()
            ))
            ->orderBy('time_in')
            ->get();
    }

    // Only finished shifts count. An overnight shift works because
    // time_in and time_out are full date-times.
    private function minutes(Collection $logs): int
    {
        return (int) round(
            $logs
                ->filter(fn ($log) => $log->time_out !== null)
                ->sum(fn ($log) => $log->time_in->diffInMinutes($log->time_out, true))
        );
    }

    private function fmt(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest > 0 ? "{$hours} h {$rest} min" : "{$hours} h";
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the question
    |--------------------------------------------------------------------------
    */

    // Lowercase, no punctuation: "Nag-apply today?" becomes "nag apply today"
    private function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    private function has(string $q, array $needles): bool
    {
        return Str::contains($q, $needles);
    }

    private function hasWord(string $q, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $q)) {
                return true;
            }
        }

        return false;
    }

    private function isGreeting(string $q): bool
    {
        return $this->hasWord($q, self::GREETING_WORDS)
            || $this->has($q, ['what can you do', 'who are you', 'ano kaya mo', 'ano ang kaya mo']);
    }

    // Returns [label, from, to]. Nulls mean "all time".
    private function range(string $q): array
    {
        if (preg_match('/(?:last|past|nakaraang)\s+(\d{1,3})\s+(?:days?|araw)/u', $q, $match)) {
            $days = max(1, min(365, (int) $match[1]));

            return ["Last {$days} days", today()->subDays($days - 1), today()];
        }

        if ($this->has($q, ['yesterday', 'kahapon'])) {
            return ['Yesterday', today()->subDay(), today()->subDay()];
        }

        if ($this->has($q, ['tomorrow', 'bukas'])) {
            return ['Tomorrow', today()->addDay(), today()->addDay()];
        }

        if ($this->has($q, ['today', 'ngayong araw', 'ngayon'])) {
            return ['Today', today(), today()];
        }

        if ($this->has($q, ['week', 'linggo'])) {
            return ['This week', today()->startOfWeek(), today()->endOfWeek()];
        }

        if ($this->has($q, ['month', 'buwan'])) {
            return ['This month', today()->startOfMonth(), today()->endOfMonth()];
        }

        return ['All time', null, null];
    }

    // Prefer the longest complete name match; otherwise allow partial-name matching.
    private function findEmployees(string $q, Collection $names): Collection
    {
        $normalizedQuery = $this->normalize($q);

        $exactNames = $names
            ->filter(function ($name) use ($normalizedQuery) {
                $normalizedName = $this->normalize((string) $name);

                return $normalizedName !== ''
                    && preg_match(
                        '/(?<![\pL\pN])' . preg_quote($normalizedName, '/') . '(?![\pL\pN])/u',
                        $normalizedQuery
                    ) === 1;
            })
            ->values();

        if ($exactNames->isNotEmpty()) {
            $longestNameLength = $exactNames->max(
                fn ($name) => mb_strlen($this->normalize((string) $name))
            );

            return $exactNames
                ->filter(
                    fn ($name) => mb_strlen($this->normalize((string) $name))
                        === $longestNameLength
                )
                ->values();
        }

        $words = collect(explode(' ', $normalizedQuery));

        return $names
            ->filter(function ($name) use ($words) {
                $tokens = collect(explode(' ', $this->normalize((string) $name)))
                    ->filter(fn ($token) => mb_strlen($token) >= 3)
                    ->reject(fn ($token) => in_array($token, self::NAME_STOP, true));

                return $tokens->intersect($words)->isNotEmpty();
            })
            ->values();
    }

    // The word after "ni", "kay", "of" or "for", if it looks like a name
    private function mentionedName(string $q): ?string
    {
        if (!preg_match('/\b(?:ni|kay|of|for)\s+(\p{L}+)/u', $q, $match)) {
            return null;
        }

        $skip = [
            'the', 'all', 'everyone', 'everybody', 'lahat', 'my', 'me', 'this', 'last',
            'today', 'yesterday', 'week', 'month', 'total', 'our', 'each', 'every',
            'mga', 'employee', 'employees',
        ];

        return in_array($match[1], $skip, true) ? null : $match[1];
    }

    /*
    |--------------------------------------------------------------------------
    | Answer shape: a title and rows. "tag" is a small label above a row.
    |--------------------------------------------------------------------------
    */

    private function row(string $text, string $level = 'low', ?string $url = null, ?string $tag = null): array
    {
        return compact('text', 'level', 'url', 'tag');
    }

    private function answer(string $title, array $items): array
    {
        return [
            'title' => $title,
            'items' => array_values($items),
        ];
    }
}