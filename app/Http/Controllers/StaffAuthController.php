<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class StaffAuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User) {
            $destination = $this->destinationFor($user);

            if ($destination !== null) {
                return redirect()->to($destination);
            }

            Auth::guard('web')->logout();
        }

        return view('staff.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge([
            'email' => mb_strtolower(
                trim((string) $request->input('email'))
            ),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        if (!Auth::guard('web')->attempt(
            $credentials,
            $request->boolean('remember')
        )) {
            return back()
                ->withErrors([
                    'email' => 'The email or password is incorrect.',
                ])
                ->onlyInput('email');
        }

        $user = Auth::guard('web')->user();
        $destination = $user instanceof User
            ? $this->destinationFor($user)
            : null;

        if ($destination === null) {
            Auth::guard('web')->logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()
                ->route('staff.login')
                ->withErrors([
                    'email' => 'This account is not configured for staff portal access. Contact the administrator.',
                ])
                ->withInput($request->only('email'));
        }

        // Prevent session fixation after successful login.
        $request->session()->regenerate();

        return redirect()->to($destination);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('staff.login')
            ->with('status', 'You have been logged out.');
    }

    private function destinationFor(User $user): ?string
    {
        return match ($user->role) {
            User::ROLE_ADMIN,
            User::ROLE_HR => route('hr.dashboard'),

            User::ROLE_SECURITY => $user->employee
                ? route('employee.dashboard')
                : null,

            default => null,
        };
    }
}