{{-- The add and edit form. $locked: the admin is editing themselves, so the role and the active flag are fixed. --}}
<x-layouts.app :title="$user->exists ? 'Edit user' : 'Add user'">
    <div class="row">
        <div class="col-lg-6">
            <div class="card">
                <div class="card-body">
                    <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}">
                        @csrf
                        @if ($user->exists)
                            @method('PUT')
                        @endif

                        <div class="mb-3">
                            <label for="name" class="form-label">Name</label>
                            <input type="text" id="name" name="name" value="{{ old('name', $user->name) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('name')])>
                            @error('name')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="email" class="form-label">Email address</label>
                            <input type="email" id="email" name="email" value="{{ old('email', $user->email) }}" required
                                @class(['form-control', 'is-invalid' => $errors->has('email')])>
                            @error('email')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @else
                                @unless ($user->exists)
                                    <div class="form-text">A link to choose a password is emailed to this address.</div>
                                @endunless
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
                            @enderror
                        </div>

                        <div class="mb-3">
                            <label for="role" class="form-label">Role</label>
                            <select id="role" @class(['form-select', 'is-invalid' => $errors->has('role')]) name="role"{{ $locked ? ' disabled' : '' }}>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->value }}"{{ $role->value === old('role', $user->role->value) ? ' selected' : '' }}>{{ $role->label() }}</option>
                                @endforeach
                            </select>
                            @error('role')
                                <div class="invalid-feedback">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="mb-3">
                            @unless ($locked)
                                <input type="hidden" name="active" value="0">
                            @endunless
                            <div class="form-check form-switch">
                                <input type="checkbox" class="form-check-input" id="active" value="1" name="active"{{ $locked ? ' disabled' : '' }}{{ old('active', $user->active) ? ' checked' : '' }}>
                                <label class="form-check-label" for="active">Active</label>
                            </div>
                            <div class="form-text">
                                @if ($locked)
                                    You cannot change your own role or deactivate yourself.
                                @else
                                    An inactive user cannot sign in. Their data is kept.
                                @endif
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">{{ $user->exists ? 'Save' : 'Add user' }}</button>
                        <a href="{{ route('users.index') }}" class="btn btn-light">Cancel</a>
                    </form>
                </div>
            </div>
        </div>
    </div>
</x-layouts.app>
