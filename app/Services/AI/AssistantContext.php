<?php

namespace App\Services\AI;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Who is asking and from which page. It is built on the server.
 * Phase 4 will fill it from the logged-in user instead of the request.
 */
final class AssistantContext
{
    public function __construct(
        public readonly string $role,
        public readonly ?string $name,
        public readonly ?int $userId,
        public readonly string $page,
        public readonly string $sessionKey,
    ) {
    }

    public static function fromRequest(
        Request $request,
        string $role,
        ?string $employeeName,
        string $page = 'dashboard'
    ): self {
        $sessionKey = $request->hasSession() ? $request->session()->getId() : 'cli';

        if ($role === 'Applicant') {
            // Always the logged-in applicant, never an id sent by the browser.
            $applicant = Auth::guard('applicant')->user();

            return new self(
                'Applicant',
                $applicant?->name,
                $applicant ? (int) $applicant->getKey() : null,
                $page,
                $sessionKey
            );
        }

        if ($role === 'Employee') {
            return new self('Employee', $employeeName ?: 'Juan Dela Cruz', null, $page, $sessionKey);
        }

        return new self('HR', null, null, $page, $sessionKey);
    }

    // One line that tells the AI who it is talking to.
    public function userLine(): string
    {
        return match ($this->role) {
            'Employee' => "{$this->name}, an employee",
            'Applicant' => $this->name
                ? "{$this->name}, a job applicant"
                : 'a job applicant who is not logged in',
            default => 'an HR staff member (name unknown)',
        };
    }
}