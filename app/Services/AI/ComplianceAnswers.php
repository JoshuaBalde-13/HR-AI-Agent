<?php

namespace App\Services\AI;

use App\Models\EmployeeDocument;
use App\Services\AI\Concerns\AnswerHelpers;
use Illuminate\Support\Collection;

/**
 * Documents: expired, expiring, complete or not. Exact answers from employee_documents.
 */
class ComplianceAnswers
{
    use AnswerHelpers;

    public const VERSION = 5;

    public const DOCUMENT_WORDS = [
        'document', 'dokumento', 'papeles', 'papel', 'expire', 'expiry', 'expired',
        'license', 'nbi', 'medical', 'renew',
    ];

    // HR: all employees, or one named employee.
    public function documents(string $q): array
    {
        $asksCompleteness = $this->asksCompleteness($q);

        // One person named: that person only.
        $matches = $this->findEmployees($q, $this->employeeNames());

        if ($matches->count() === 1) {
            $name = $matches->first();

            return $asksCompleteness
                ? $this->completeness($name, false, $q)
                : $this->employeeDocuments($name, false, $q);
        }

        // "Which employees have incomplete documents?"
        if ($asksCompleteness) {
            return $this->incompleteEmployees($q);
        }

        $total = EmployeeDocument::count();

        if ($total === 0) {
            return $this->answer('Documents', [
                $this->row('No employee documents are on file yet, so there is nothing to check.', 'medium'),
            ]);
        }

        [$wantExpired, $wantSoon] = $this->kinds($q);
        [$windowLabel, $end] = $this->expiryWindow($q);
        $urgent = (int) config('hris.urgent_days', 7);
        $yes = $this->isYesNo($q);

        $expired = $wantExpired
            ? EmployeeDocument::whereDate('expiry_date', '<', today())->orderBy('expiry_date')->get()
            : collect();

        $soon = $wantSoon
            ? EmployeeDocument::whereDate('expiry_date', '>=', today())->whereDate('expiry_date', '<=', $end)->orderBy('expiry_date')->get()
            : collect();

        $nExpired = $expired->count();
        $nSoon = $soon->count();

        if ($wantExpired && $wantSoon) {
            if (!$nExpired && !$nSoon) {
                return $this->answer('Documents', [
                    $this->row($this->verdict($yes, false, "no document is expired or expires {$windowLabel}.", "No document is expired or expires {$windowLabel}."), 'low'),
                ]);
            }

            $parts = [];

            if ($nExpired) {
                $parts[] = "{$nExpired} expired";
            }

            if ($nSoon) {
                $parts[] = "{$nSoon} expiring {$windowLabel}";
            }

            $text = implode(' and ', $parts) . '.';
            $plain = 'Documents: ' . $text;
        } elseif ($wantExpired) {
            if (!$nExpired) {
                return $this->answer('Documents', [
                    $this->row($this->verdict($yes, false, 'no document is expired.', 'No document is expired.'), 'low'),
                ]);
            }

            $text = $this->countOf($nExpired, 'document') . ($nExpired === 1 ? ' is' : ' are') . ' expired.';
            $plain = $text;
        } else {
            if (!$nSoon) {
                return $this->answer('Documents', [
                    $this->row($this->verdict($yes, false, "no document expires {$windowLabel}.", "No document expires {$windowLabel}."), 'low'),
                ]);
            }

            $text = $this->countOf($nSoon, 'document') . ($nSoon === 1 ? ' expires' : ' expire') . " {$windowLabel}.";
            $plain = $text;
        }

        $hasUrgent = $soon->contains(
            fn ($document) => (int) today()->diffInDays($document->expiry_date, true) <= $urgent
        );

        $items = [
            $this->row(
                $this->verdict($yes, true, $text, $plain),
                $nExpired ? 'critical' : ($hasUrgent ? 'high' : 'medium'),
                route('hr.compliance')
            ),
        ];

        // "Ilan ang expired?" is only a number.
        if (!$this->isCount($q)) {
            foreach ($expired->take(6) as $document) {
                $days = (int) $document->expiry_date->diffInDays(today(), true);

                $items[] = $this->row("{$document->employee_name}: {$document->document_type}, expired {$this->countOf($days, 'day')} ago", 'high');
            }

            foreach ($soon->take(6) as $document) {
                $days = (int) today()->diffInDays($document->expiry_date, true);

                $items[] = $this->row(
                    "{$document->employee_name}: {$document->document_type}, {$this->countOf($days, 'day')} left",
                    $days <= $urgent ? 'high' : 'medium'
                );
            }
        }

        return $this->answer('Documents', $items);
    }

    // The documents of one person, only the kind that was asked.
    public function employeeDocuments(string $name, bool $self = false, string $q = ''): array
    {
        $documents = EmployeeDocument::where('employee_name', $name)->orderBy('expiry_date')->get();

        $mid = $self ? 'you' : $name;
        $subject = $self ? 'You' : $name;
        $has = $self ? 'have' : 'has';
        $person = $self ? null : $name;
        $yes = $this->isYesNo($q);

        if ($documents->isEmpty()) {
            return $this->answer('Documents', [
                $this->row($this->say($yes, false, $mid, $subject, "{$has} no documents on file yet."), 'medium'),
            ], $person);
        }

        $urgent = (int) config('hris.urgent_days', 7);
        $warning = (int) config('hris.warning_days', 30);
        [$wantExpired, $wantSoon] = $this->kinds($q);
        [$windowLabel, $end] = $this->expiryWindow($q);

        $expired = $documents->filter(fn ($d) => $d->expiry_date->lt(today()))->values();

        // The window follows the question ("this month"); otherwise the usual warning days.
        $soon = $documents->filter(
            fn ($d) => $d->expiry_date->gte(today()) && $d->expiry_date->lte($end)
        )->values();

        // Only one kind was asked: "may expired ba siyang document?"
        if ($wantExpired xor $wantSoon) {
            $list = $wantExpired ? $expired : $soon;

            if ($list->isEmpty()) {
                $tail = $wantExpired ? "{$has} no expired document." : "{$has} no document expiring {$windowLabel}.";

                return $this->answer('Documents', [
                    $this->row($this->say($yes, false, $mid, $subject, $tail), 'low'),
                ], $person);
            }

            $n = $list->count();

            $what = $wantExpired
                ? ($n === 1 ? 'expired document' : 'expired documents')
                : ($n === 1 ? 'document' : 'documents') . " expiring {$windowLabel}";

            $detail = '';

            if ($n === 1) {
                $only = $list->first();
                $days = (int) today()->diffInDays($only->expiry_date, true);

                $detail = $wantExpired
                    ? ": {$only->document_type} ({$this->countOf($days, 'day')} overdue)"
                    : ": {$only->document_type} ({$this->countOf($days, 'day')} left)";
            }

            $items = [
                $this->row($this->say($yes, true, $mid, $subject, "{$has} {$n} {$what}{$detail}."), $wantExpired ? 'critical' : 'medium'),
            ];

            if ($n > 1) {
                foreach ($list as $document) {
                    $days = (int) today()->diffInDays($document->expiry_date, true);

                    $items[] = $this->row(
                        $wantExpired
                            ? "{$document->document_type}: expired {$this->countOf($days, 'day')} ago"
                            : "{$document->document_type}: {$this->countOf($days, 'day')} left",
                        $wantExpired ? 'high' : ($days <= $urgent ? 'high' : 'medium')
                    );
                }
            }

            return $this->answer('Documents', $items, $person);
        }

        // Everything: "documents ni Joshua"
        $valid = $documents->count() - $expired->count() - $documents->filter(
            fn ($d) => $d->expiry_date->gte(today()) && $d->expiry_date->lte(today()->addDays($warning))
        )->count();

        $soonDefault = $documents->filter(
            fn ($d) => $d->expiry_date->gte(today()) && $d->expiry_date->lte(today()->addDays($warning))
        );

        $bits = [];

        if ($expired->isNotEmpty()) {
            $bits[] = "{$expired->count()} expired";
        }

        if ($soonDefault->isNotEmpty()) {
            $bits[] = "{$soonDefault->count()} expiring within {$warning} days";
        }

        if ($valid > 0) {
            $bits[] = "{$valid} valid";
        }

        $items = [
            $this->row(
                ($self ? 'Your documents: ' : "Documents of {$name}: ") . implode(', ', $bits) . '.',
                $expired->isNotEmpty() ? 'critical' : ($soonDefault->isNotEmpty() ? 'medium' : 'low')
            ),
        ];

        foreach ($documents as $document) {
            $days = (int) today()->diffInDays($document->expiry_date, true);

            if ($document->expiry_date->lt(today())) {
                $items[] = $this->row("{$document->document_type}: expired {$this->countOf($days, 'day')} ago", 'critical');
            } elseif ($document->expiry_date->lte(today()->addDays($warning))) {
                $items[] = $this->row("{$document->document_type}: {$this->countOf($days, 'day')} left", $days <= $urgent ? 'high' : 'medium');
            } else {
                $items[] = $this->row("{$document->document_type}: valid until " . $document->expiry_date->format('M j, Y'));
            }
        }

        return $this->answer('Documents', $items, $person);
    }

    // "Is Joshua's record complete?": yes or no, and what is missing.
    public function completeness(string $name, bool $self = false, string $q = ''): array
    {
        $required = $this->requiredDocuments();
        $person = $self ? null : $name;

        if (!$required) {
            return $this->answer('Documents', [
                $this->row('No required documents are set yet. Add them in config/hris.php.', 'medium'),
            ], $person);
        }

        $documents = EmployeeDocument::where('employee_name', $name)->get();
        $missing = $this->missingFrom($documents->pluck('document_type'), $required);
        $have = count($required) - count($missing);
        $yes = $this->isYesNo($q);

        if (!$missing) {
            $text = $self
                ? "your documents are complete ({$have} of " . count($required) . ' required).'
                : "the documents of {$name} are complete ({$have} of " . count($required) . ' required).';

            return $this->answer('Documents', [
                $this->row($this->verdict($yes, true, $text, ucfirst($text))),
            ], $person);
        }

        $text = ($self ? 'you are' : "{$name} is") . ' missing ' . $this->countOf(count($missing), 'required document')
            . ': ' . implode(', ', $missing) . " ({$have} of " . count($required) . ' on file).';

        return $this->answer('Documents', [
            $this->row($this->verdict($yes, false, $text, ucfirst($text)), 'high'),
        ], $person);
    }

    // "Which employees have incomplete documents?"
    public function incompleteEmployees(string $q = ''): array
    {
        $required = $this->requiredDocuments();
        $yes = $this->isYesNo($q);

        if (!$required) {
            return $this->answer('Documents', [
                $this->row('No required documents are set yet. Add them in config/hris.php.', 'medium'),
            ]);
        }

        if ($this->employeeNames()->isEmpty()) {
            return $this->answer('Documents', [
                $this->row('There are no employees on file yet.', 'medium'),
            ]);
        }

        $list = $this->incompleteList();

        if ($list->isEmpty()) {
            return $this->answer('Documents', [
                $this->row($this->verdict($yes, false, 'every employee has all ' . count($required) . ' required documents.', 'Every employee has all ' . count($required) . ' required documents.')),
            ]);
        }

        $count = $list->count();

        $items = [
            $this->row(
                $this->verdict($yes, true, $this->countOf($count, 'employee') . ($count === 1 ? ' is' : ' are') . ' missing required documents.'),
                'high',
                route('hr.compliance')
            ),
        ];

        if (!$this->isCount($q)) {
            foreach ($list->take(8) as $name => $missing) {
                $items[] = $this->row("{$name}: missing " . implode(', ', $missing), 'medium');
            }

            if ($count > 8) {
                $items[] = $this->row('+' . ($count - 8) . ' more');
            }
        }

        return $this->answer('Documents', $items, $count === 1 ? (string) $list->keys()->first() : null);
    }

    // name => list of missing required documents, only for employees that miss something.
    public function incompleteList(): Collection
    {
        $required = $this->requiredDocuments();

        if (!$required) {
            return collect();
        }

        $types = EmployeeDocument::query()
            ->get(['employee_name', 'document_type'])
            ->groupBy('employee_name')
            ->map(fn (Collection $group) => $group->pluck('document_type'));

        $list = collect();

        foreach ($this->employeeNames() as $name) {
            $missing = $this->missingFrom($types->get($name, collect()), $required);

            if ($missing) {
                $list->put($name, $missing);
            }
        }

        return $list;
    }

    // Counts for the HR overview.
    public function summaryCounts(): array
    {
        $warning = (int) config('hris.warning_days', 30);

        return [
            'expired' => EmployeeDocument::whereDate('expiry_date', '<', today())->count(),
            'soon' => EmployeeDocument::whereDate('expiry_date', '>=', today())
                ->whereDate('expiry_date', '<=', today()->addDays($warning))
                ->count(),
            'incomplete' => $this->incompleteList()->count(),
            'warning' => $warning,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reading the question
    |--------------------------------------------------------------------------
    */

    // [wants expired, wants expiring soon]. If neither is said, both.
    private function kinds(string $q): array
    {
        $expired = $this->has($q, ['expired', 'expire na', 'paso na', 'nag expire na', 'overdue', 'lampas']);

        $soon = $this->has($q, [
            'expiring', 'mag expire', 'magexpire', 'mag eexpire', 'ma expire', 'pa expire',
            'malapit', 'soon', 'upcoming', 'sa loob ng', 'within', 'this month', 'this week',
            'ngayong buwan', 'ngayong linggo', 'susunod',
        ]);

        if (!$expired && !$soon) {
            return [true, true];
        }

        return [$expired, $soon];
    }

    // Returns [label, last day]. "within 30 days" unless the question says otherwise.
    private function expiryWindow(string $q): array
    {
        if (preg_match('/(?:within|sa loob ng|next|susunod na)\s+(\d{1,3})\s+(?:days?|araw)/u', $q, $match)) {
            $days = max(1, min(365, (int) $match[1]));

            return ["within {$days} days", today()->addDays($days)];
        }

        if ($this->has($q, ['this month', 'ngayong buwan'])) {
            return ['this month', today()->endOfMonth()];
        }

        if ($this->has($q, ['this week', 'ngayong linggo'])) {
            return ['this week', today()->endOfWeek()];
        }

        $days = (int) config('hris.warning_days', 30);

        return ["within {$days} days", today()->addDays($days)];
    }
}