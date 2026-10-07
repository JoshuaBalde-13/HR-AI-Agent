<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    /**
     * Every status where HR still has work to do.
     */
    public const PENDING = [
        'submitted',
        'under_review',
        'interview_scheduled',
        'interview_completed',
    ];

    protected $fillable = [
        'applicant_id',
        'job_title',
        'status',
        'step',
        'phone',
        'address',
        'remarks',
        'submitted_at',
        'reviewed_at',
        'interview_at',
        'interview_location',
        'interview_completed_at',
    ];

    protected $casts = [
        'step' => 'integer',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'interview_at' => 'datetime',
        'interview_completed_at' => 'datetime',
    ];

    public function applicant(): BelongsTo
    {
        return $this->belongsTo(Applicant::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return in_array($this->status, self::PENDING, true);
    }

    /**
     * Human-friendly label for the status column.
     */
    public function statusLabel(): string
    {
        return match ($this->status) {
            'draft' => 'In progress',
            'submitted' => 'Submitted',
            'under_review' => 'Under review',
            'interview_scheduled' => 'Interview scheduled',
            'interview_completed' => 'Interview completed',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($this->status),
        };
    }
}