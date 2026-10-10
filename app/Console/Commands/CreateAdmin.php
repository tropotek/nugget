<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'nugget:create-admin
                            {email : Email address of the admin to create, or of the user to promote}
                            {--name= : Name (asked for when omitted)}
                            {--password= : Password (asked for when omitted)}
                            {--timezone= : Timezone, such as Australia/Perth}';

    protected $description = 'Create an admin, or promote an existing user to admin';

    public function handle(): int
    {
        $email = Str::lower(trim($this->argument('email')));

        if ($user = User::where('email', $email)->first()) {
            return $this->promote($user);
        }

        // The email is checked first so a typo is not followed by questions.
        if (! $this->passes(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']])) {
            return self::FAILURE;
        }

        $data = [
            'email' => $email,
            'name' => $this->option('name') ?? text('Name', required: true),
            'password' => $this->option('password') ?? password('Password', required: true),
            'timezone' => $this->option('timezone') ?? User::DEFAULT_TIMEZONE,
        ];

        $valid = $this->passes($data, [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()],
            'timezone' => ['required', 'timezone:all'],
        ]);

        if (! $valid) {
            return self::FAILURE;
        }

        $user = new User($data);
        $user->forceFill(['role' => Role::Admin])->save();

        $this->components->info("Created admin '{$user->email}'.");

        return self::SUCCESS;
    }

    private function promote(User $user): int
    {
        if ($user->isAdmin()) {
            $this->components->info("'{$user->email}' is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['role' => Role::Admin])->save();

        $this->components->info("Promoted '{$user->email}' to admin.");

        return self::SUCCESS;
    }

    /**
     * Validate the data, printing each error.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     */
    private function passes(array $data, array $rules): bool
    {
        $validator = Validator::make($data, $rules);

        foreach ($validator->errors()->all() as $error) {
            $this->components->error($error);
        }

        return $validator->passes();
    }
}
