<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'tokens' => $request->user()->tokens()->latest()->latest('id')->get(),
        ]);
    }

    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->update($request->safe()->only(['name', 'email', 'timezone']));

        return redirect()->route('profile.edit')->with('success', 'Profile saved.');
    }

    /**
     * A new remember token signs out the remember-me cookies on other devices.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validateWithBag('updatePassword', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->forceFill([
            'password' => $validated['password'],
            'remember_token' => Str::random(60),
        ])->save();

        return redirect()->route('profile.edit')->with('success', 'Password changed.');
    }
}
