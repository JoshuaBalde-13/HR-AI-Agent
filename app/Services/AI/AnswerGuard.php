<?php

namespace App\Services\AI;

use Illuminate\Support\Str;

/**
 * Checks on what the AI writes before a user sees it.
 */
class AnswerGuard
{
    // Lowercase, no punctuation: "Nag-apply today?" becomes "nag apply today"
    public function normalize(string $text): string
    {
        $text = Str::lower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $text);

        return trim(preg_replace('/\s+/', ' ', $text));
    }

    // Plain text only: no emoji, no markdown.
    public function plain(string $text): string
    {
        $text = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{26FF}\x{2705}\x{274C}\x{274E}\x{2728}\x{2753}\x{2757}\x{27A1}\x{2B50}\x{2B55}\x{231A}\x{231B}\x{23E9}-\x{23F3}\x{FE0F}\x{200D}]+/u',
            '',
            $text
        );

        return trim(str_replace(['**', '__', '`'], '', (string) $text));
    }

    // Every number in the answer must appear in the data (or in the question).
    // This stops the model from inventing counts or hours.
    public function isGrounded(string $answer, string $question, string $data): bool
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

    // Does the (normalized) question sound like it is about the HRIS?
    public function looksLikeHris(string $q): bool
    {
        return Str::contains($q, [
            'applicant', 'application', 'aplikante', 'aplikasyon', 'apply', 'approved', 'rejected',
            'natanggap', 'nakapasa', 'pumasa', 'interview', 'status', 'hours', 'oras', 'duty',
            'shift', 'attendance', 'employee', 'empleyado', 'document', 'dokumento', 'expire',
            'license', 'schedule', 'remarks', 'submitted', 'pending',
        ]) || (bool) preg_match('/\bhr\b/u', $q);
    }
}