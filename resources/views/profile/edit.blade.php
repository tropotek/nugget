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
</x-layouts.app>
