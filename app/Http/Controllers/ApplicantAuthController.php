<?php

namespace App\Http\Controllers;

use App\Models\Applicant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class ApplicantAuthController extends Controller
{
    private function guard()
    {
        return Auth::guard('applicant');
    }

    public function showRegister()
    {
        if ($this->guard()->check()) {
            return redirect()->route('applicant.dashboard', $this->guard()->user());
        }

        return view('auth.register');
    }

    public function register(Request $request): RedirectResponse
    {
        // "Maria@Mail.com " and "maria@mail.com" are the same account.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:applicants,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'email.unique' => 'That email is already registered. Try logging in instead.',
            'password.confirmed' => 'The passwords do not match.',
            'password.min' => 'Your password must be at least 8 characters.',
        ]);

        $applicant = Applicant::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $this->guard()->login($applicant);
        $request->session()->regenerate();

        return redirect()
            ->route('applicant.dashboard', $applicant)
            ->with('success', 'Account created. Welcome, ' . $applicant->name . '!');
    }

    public function showLogin()
    {
        if ($this->guard()->check()) {
            return redirect()->route('applicant.dashboard', $this->guard()->user());
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (!$this->guard()->attempt($credentials, $request->boolean('remember'))) {
            return back()
                ->withErrors(['email' => 'The email or password is incorrect.'])
                ->onlyInput('email');
        }

        $request->session()->regenerate();

        return redirect()->intended(
            route('applicant.dashboard', $this->guard()->user())
        );
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'You have been logged out.');
    }
}