<x-layouts.guest title="Forgot password">
    <p class="text-muted mb-4">Enter your email address and we will send you a link to choose a new password.</p>

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Email the link</button>
        </div>
    </form>

    <x-slot:below>
        <a href="{{ route('login') }}" class="text-white-50">Back to log in</a>
    </x-slot:below>
</x-layouts.guest>
