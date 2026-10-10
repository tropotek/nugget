<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Display the password reset link request view.
     */
    public function create(): View
    {
        return view('auth.forgot-password');
    }

    /**
     * Always answers the same, whether or not the email has an account, so the
     * form can't be used to discover accounts.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Emails are stored in lower case, and SQLite compares them exactly.
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $key = 'recover:'.Str::transliterate($request->string('email').'|'.$request->ip());
        $throttled = RateLimiter::tooManyAttempts($key, config('nugget.login.max_attempts'));
        RateLimiter::hit($key, config('nugget.login.lockout_minutes') * 60);

        // A send only fails for a real account, so showing the failure would
        // reveal which emails exist. Log it and reply as normal.
        if (! $throttled) {
            try {
                Password::sendResetLink($request->only('email'));
            } catch (Throwable $e) {
                report($e);
            }
        }

        return back()->with('status', __('If that email belongs to an account, a reset link is on its way.'));
    }
}
