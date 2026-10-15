<?php

namespace App\Services\AI\Concerns;

use App\Models\AttendanceLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Small tools shared by every answer class: the answer shape, reading the
 * question (names, periods, yes/no, how many, who, when) and a few lookups.
 */
trait AnswerHelpers
{
    /*
    |--------------------------------------------------------------------------
    | Answer shape
    |--------------------------------------------------------------------------
    |
    | title:  a small label above the answer (can be empty).
    | lead:   true means the first row is the direct answer, shown as a sentence.
    | items:  the first row is the answer, the others are short bullets.
    | person: the person the answer is about, so "siya" can be understood later.
    |
    */

    protected function row(string $text, string $level = 'low', ?string $url = null, ?string $tag = null): array
    {
        return compact('text', 'level', 'url', 'tag');
    }

    protected function answer(string $title, array $items, ?string $person = null): array
    {
        return [
            'title' => $title,
            'lead' => $items !== [],
            'items' => array_values($items),
            'person' => $person,
        ];
    }

    // "Yes, ..." / "No, ..." for yes/no questions, a plain sentence otherwise.
    protected function verdict(bool $yesNo, bool $positive, string $text, ?string $plain = null): string
    {
        if ($yesNo) {
            return ($positive ? 'Yes, ' : 'No, ') . $text;
        }

        return $plain ?? $text;
    }

    // "Yes, you have ..." (mid) or "You have ..." (subject) with the same ending.
    protected function say(bool $yesNo, bool $positive, string $mid, string $subject, string $tail): string
    {
        return $this->verdict($yesNo, $positive, "{$mid} {$tail}", "{$subject} {$tail}");
    }

    protected function countOf(int $number, string $noun): string
    {
        return $number . ' ' . $noun . ($number === 1 ? '' : 's');
    }

    protected function fmt(int $minutes): string
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
    protected function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    protected function has(string $q, array $needles): bool
    {
        return Str::contains($q, $needles);
    }

    protected function hasWord(string $q, array $words): bool
    {
        foreach ($words as $word) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $q)) {
                return true;
            }
        }

        return false;
    }

    // "Ilan ...?" and "how many ...?" want a number.
    protected function isCount(string $q): bool
    {
        return $this->hasWord($q, ['ilan', 'ilang']) || $this->has($q, ['how many', 'bilang ng', 'total number']);
    }

    // "Sino ...?" wants names.
    protected function isWho(string $q): bool
    {
        return $this->hasWord($q, ['sino', 'who']) || $this->has($q, ['which employees', 'which applicants', 'sino sino']);
    }

    // "Kailan ...?" wants a date.
    protected function isWhen(string $q): bool
    {
        return $this->hasWord($q, ['kailan', 'when']) || $this->has($q, ['since when']);
    }

    // "...ba?" (Tagalog) and "Is/Are/Do/Does ...?" want Yes or No.
    protected function isYesNo(string $q): bool
    {
        return $this->hasWord($q, ['ba']) || preg_match('/^(is|are|do|does|did|has|have|can|was|were)\b/u', $q) === 1;
    }

    // Matched as whole words, so "interview completed" is not a completeness question.
    protected function asksCompleteness(string $q): bool
    {
        return $this->hasWord($q, ['kumpleto', 'complete', 'incomplete', 'missing', 'kulang', 'requirement', 'requirements']);
    }

    // "today", "last month"... or "in total" when no period was asked.
    protected function when(string $label, ?Carbon $from): string
    {
        return $from ? Str::lower($label) : 'in total';
    }

    // Returns [label, from, to]. Nulls mean "all time".
    protected function range(string $q): array
    {
        if (preg_match('/(?:last|past|nakaraang)\s+(\d{1,3})\s+(?:days?|araw)/u', $q, $match)) {
            $days = max(1, min(365, (int) $match[1]));

            return ["Last {$days} days", today()->subDays($days - 1), today()];
        }

        // "last week" must be checked before the plain word "week".
        if ($this->has($q, ['last week', 'previous week', 'nakaraang linggo', 'noong isang linggo'])) {
            $start = today()->subWeek()->startOfWeek();

            return ['Last week', $start, $start->copy()->endOfWeek()];
        }

        if ($this->has($q, ['last month', 'previous month', 'nakaraang buwan', 'noong isang buwan'])) {
            $start = today()->subMonthNoOverflow()->startOfMonth();

            return ['Last month', $start, $start->copy()->endOfMonth()];
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

    // Common words that must never count as part of a person's name.
    protected function nameStop(): array
    {
        return [
            'the', 'and', 'for', 'who', 'how', 'many', 'any', 'are', 'there', 'our', 'has', 'have',
            'did', 'does', 'what', 'when', 'where', 'which', 'with', 'from', 'this', 'that', 'about',
            'tell', 'show', 'list', 'please', 'status', 'today', 'week', 'month', 'yesterday', 'tomorrow',
            'ito', 'yung', 'ang', 'mga', 'ng', 'sa', 'ni', 'kay', 'ba', 'na', 'po', 'ano', 'sino',
            'ilan', 'may', 'meron', 'mayroon', 'natin', 'namin', 'nila', 'siya', 'lahat', 'tungkol',
            'ngayon', 'kahapon', 'bukas', 'paki',
        ];
    }

    // Matches a first, middle or last name: "ni joshua" finds "Joshua Balde".
    // When several names share a part ("kym mendroga" and "mendroga"), the one
    // whose full name is written in the question wins, longest first.
    protected function findEmployees(string $q, Collection $names): Collection
    {
        $words = collect(explode(' ', $q));
        $stop = $this->nameStop();

        $matches = $names
            ->filter(function ($name) use ($words, $stop) {
                $tokens = collect(explode(' ', $this->normalize((string) $name)))
                    ->filter(fn ($token) => mb_strlen($token) >= 3)
                    ->reject(fn ($token) => in_array($token, $stop, true));

                return $tokens->intersect($words)->isNotEmpty();
            })
            ->values();

        if ($matches->count() < 2) {
            return $matches;
        }

        $padded = " {$q} ";

        $written = $matches->filter(
            fn ($name) => str_contains($padded, ' ' . $this->normalize((string) $name) . ' ')
        );

        if ($written->isEmpty()) {
            return $matches;
        }

        $longest = $written->max(fn ($name) => mb_strlen($this->normalize((string) $name)));

        return $written
            ->filter(fn ($name) => mb_strlen($this->normalize((string) $name)) === $longest)
            ->values();
    }

    // The word after "ni", "kay", "of" or "for", if it looks like a name
    protected function mentionedName(string $q): ?string
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
    | Shared lookups
    |--------------------------------------------------------------------------
    */

    // Everyone who is an employee: the employees table, plus anyone who only
    // appears in attendance or documents (older records).
    protected function employeeNames(): Collection
    {
        return Employee::query()->pluck('name')
            ->merge(AttendanceLog::query()->distinct()->pluck('employee_name'))
            ->merge(EmployeeDocument::query()->distinct()->pluck('employee_name'))
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    protected function requiredDocuments(): array
    {
        return array_values(array_filter((array) config('hris.required_employee_documents', [])));
    }

    // The required document types that are not in $types.
    protected function missingFrom(Collection $types, array $required): array
    {
        $have = $types->map(fn ($type) => mb_strtolower(trim((string) $type)));

        return array_values(array_filter(
            $required,
            fn ($type) => !$have->contains(mb_strtolower($type))
        ));
    }
}