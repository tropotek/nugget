<?php

namespace App\Http\Requests\Admin;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user ? $this->user()->can('update', $user) : $this->user()->can('create', User::class);
    }

    /**
     * Emails are stored in lower case, and SQLite compares them exactly.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Str::lower(trim((string) $this->input('email')))]);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)->ignore($this->route('user'))],
            'timezone' => ['required', 'string', 'timezone:all'],
            'role' => [Rule::requiredIf($this->changesAccess()), Rule::enum(Role::class)],
            'active' => ['boolean'],
        ];
    }

    /**
     * Whether the role and the active flag are the admin's to set: always for
     * a new user, and for any existing user but themselves.
     */
    public function changesAccess(): bool
    {
        $user = $this->route('user');

        return ! $user || $this->user()->can('changeAccess', $user);
    }
}
