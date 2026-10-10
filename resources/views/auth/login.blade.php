<x-layouts.guest title="Log in">
    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                @class(['form-control', 'is-invalid' => $errors->has('email')])>
            @error('email')
                <div class="invalid-feedback">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <div class="input-group input-group-merge">
                <input type="password" id="password" name="password" required autocomplete="current-password"
                    @class(['form-control', 'is-invalid' => $errors->has('password')])>
                <div class="input-group-text" data-password="false">
                    <span class="password-eye"></span>
                </div>
            </div>
            @error('password')
                <div class="invalid-feedback d-block">{{ $message }}</div>
            @enderror
        </div>

        <div class="mb-3">
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                <label class="form-check-label" for="remember">Remember me</label>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary">Log in</button>
        </div>
    </form>

    <x-slot:below>
        <a href="{{ route('password.request') }}" class="text-white-50">Forgot your password?</a>
    </x-slot:below>
</x-layouts.guest>
