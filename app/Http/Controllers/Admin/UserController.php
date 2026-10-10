<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserRequest;
use App\Models\User;
use App\Notifications\SetPassword;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', User::class);

        $q = trim((string) $request->query('q'));

        $users = User::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($where) => $where
                ->where('name', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%")))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('users.index', ['users' => $users, 'q' => $q]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return $this->form(new User);
    }

    /**
     * The user gets a password nobody knows and an email with a link to choose
     * their own. Authorised by UserRequest.
     */
    public function store(UserRequest $request): RedirectResponse
    {
        $user = new User($request->safe()->only(['name', 'email', 'timezone']));
        $user->forceFill([
            'password' => Str::password(40),
            'role' => Role::from($request->validated('role')),
            'active' => $request->boolean('active'),
        ])->save();

        try {
            $user->notify(new SetPassword(Password::createToken($user)));
        } catch (Throwable $e) {
            report($e);

            return redirect()->route('users.index')->with(
                'warning',
                "Created {$user->name}, but the set-password email could not be sent. They can use \"Forgot your password?\" on the login page.",
            );
        }

        return redirect()->route('users.index')
            ->with('success', "Created {$user->name}. A set-password email is on its way to {$user->email}.");
    }

    public function edit(User $user): View
    {
        Gate::authorize('update', $user);

        return $this->form($user);
    }

    /** Authorised by UserRequest. */
    public function update(UserRequest $request, User $user): RedirectResponse
    {
        $user->fill($request->safe()->only(['name', 'email', 'timezone']));

        if ($request->changesAccess()) {
            $user->forceFill([
                'role' => Role::from($request->validated('role')),
                'active' => $request->boolean('active'),
            ]);
        }

        $user->save();

        return redirect()->route('users.index')->with('success', "Saved {$user->name}.");
    }

    private function form(User $user): View
    {
        return view('users.form', [
            'user' => $user,
            'roles' => Role::cases(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'locked' => $user->exists && Gate::denies('changeAccess', $user),
        ]);
    }
}
