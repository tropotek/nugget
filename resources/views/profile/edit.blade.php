<x-layouts.app title="Profile">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Details</h4>

                    <form method="POST" action="{{ route('profile.update') }}">
                        @csrf
                        @method('PATCH')

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required autocomplete="name"
                                @class(['form-control', 'is-invalid' => $errors->has('name')])>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="timezone" class="form-label">Timezone</label>
                            <select id="timezone" name="timezone" required
                                @class(['form-select', 'is-invalid' => $errors->has('timezone')])>
                                @foreach ($timezones as $timezone)
                                    <option value="{{ $timezone }}"{{ $timezone === old('timezone', $user->timezone) ? ' selected' : '' }}>{{ $timezone }}</option>
                                @endforeach
                            </select>
                            @error('timezone')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Dates and times are shown in this timezone.</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="username"
                                @class(['form-control', 'is-invalid' => $errors->has('email')])>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email_current_password" class="form-label">Current password</label>
                            <input type="password" id="email_current_password" name="current_password" autocomplete="current-password"
                                @class(['form-control', 'is-invalid' => $errors->has('current_password')])>
                            @error('current_password')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                <div class="form-text">Only needed to change your email address.</div>
                            @enderror
                        </div>

                        <button type="submit" class="btn btn-primary">Save</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h4 class="header-title mb-3">Change password</h4>

                    <form method="POST" action="{{ route('profile.password') }}">
                        @csrf
                        @method('PUT')

                        <div class="mb-3">
                            <label for="current_password" class="form-label">Current password</label>
                            <input type="password" id="current_password" name="current_password" required autocomplete="current-password"
                                @class(['form-control', 'is-invalid' => $errors->updatePassword->has('current_password')])>
                            @error('current_password', 'updatePassword')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password" class="form-label">New password</label>
                            <input type="password" id="password" name="password" required autocomplete="new-password"
                                @class(['form-control', 'is-invalid' => $errors->updatePassword->has('password')])>
                            @error('password', 'updatePassword')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="password_confirmation" class="form-label">New password again</label>
                            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                                class="form-control">
                        </div>

                        <button type="submit" class="btn btn-primary">Change password</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- $tokens: the user's API tokens, newest first. The text of one just made is in the session as api_token. --}}
    <div class="row">
        <div class="col-lg-12">
            <div class="card" id="api-tokens">
                <div class="card-body">
                    <h4 class="header-title mb-1">API tokens</h4>
                    <p class="text-muted">
                        A token lets a program read your figures through the API at <code>{{ url('/api/v1') }}</code>. It can read and nothing else:
                        it cannot change the ledger, and it never sees an exchange key or secret. Revoke one and it stops working at once.
                    </p>

                    @if (session('api_token'))
                        <div class="alert alert-success" role="alert" id="new-api-token">
                            <div class="mb-1"><strong>Your new token.</strong> Copy it now: it is not shown again.</div>
                            <input type="text" readonly class="form-control font-monospace" value="{{ session('api_token') }}" onfocus="this.select()" aria-label="The new token">
                        </div>
                    @endif

                    <form method="POST" action="{{ route('profile.tokens.store') }}" class="row g-2 mb-3">
                        @csrf
                        <div class="col-sm-6 col-lg-4">
                            <label for="token_name" class="visually-hidden">Token name</label>
                            <input type="text" id="token_name" name="token_name" value="{{ old('token_name') }}" required maxlength="100" placeholder="What it is for, such as Claude"
                                @class(['form-control', 'is-invalid' => $errors->apiToken->has('token_name')])>
                            @error('token_name', 'apiToken')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="col-auto">
                            <button type="submit" class="btn btn-primary">Make a token</button>
                        </div>
                    </form>

                    <div class="table-responsive">
                        <table class="table table-centered mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Name</th>
                                    <th>Made</th>
                                    <th>Last used</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse ($tokens as $token)
                                    <tr>
                                        <td>{{ $token->name }}</td>
                                        <td class="text-nowrap"><x-date :value="$token->created_at" /></td>
                                        <td class="text-nowrap">
                                            @if ($token->last_used_at)
                                                <x-date :value="$token->last_used_at" />
                                            @else
                                                <span class="text-muted">Never</span>
                                            @endif
                                        </td>
                                        <td class="text-end">
                                            <form method="POST" action="{{ route('profile.tokens.destroy', $token) }}">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Revoke</button>
                                            </form>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-muted">No tokens yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
