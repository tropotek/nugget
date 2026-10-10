<x-layouts.guest title="Choose a new password">
    <form method="POST" action="{{ route('password.store') }}">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input type="password" id="password" name="password" required autofocus autocomplete="new-password"
                @class(['form-control', 'is-invalid' => $errors->has('password')])>
            @error('password')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">New password again</label>
            <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                class="form-control">
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Save the new password</button>
        </div>
    </form>

    <x-slot:below>
        <a href="{{ route('login') }}" class="text-white-50">Back to log in</a>
    </x-slot:below>
</x-layouts.guest>
