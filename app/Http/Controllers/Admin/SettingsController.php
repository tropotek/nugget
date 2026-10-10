<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ParcelMatching;
use App\Facades\Settings;
use App\Http\Controllers\Controller;
use App\Support\Settings as SettingsStore;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(): View
    {
        Gate::authorize('admin');

        return view('settings.edit', [
            'settings' => Settings::all(),
            'methods' => ParcelMatching::cases(),
            'years' => range(Settings::currentYear(), SettingsStore::EARLIEST_YEAR),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('admin');

        $rules = array_map(fn (array $definition) => $definition['rules'], Settings::definitions());

        foreach ($request->validate($rules) as $key => $value) {
            Settings::set($key, $value);
        }

        return redirect()->route('settings.edit')->with('success', 'Settings saved.');
    }
}
