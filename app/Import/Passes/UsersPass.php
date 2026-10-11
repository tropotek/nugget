<?php

namespace App\Import\Passes;

use App\Enums\Role;
use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * tkbtc's users: a `user` row joined to its `auth` row, which has the email,
 * the password hash, the timezone and whether it is active.
 *
 * A user whose email is already in Nugget is matched and left as it is. An
 * inactive user with no exchange and no asset in tkbtc is passed over: they
 * could not sign in and own nothing, and Nugget never deletes a user. A
 * password that Nugget can verify (bcrypt) is carried over; any other user
 * gets one nobody knows and has to use "Forgot your password?".
 *
 * Users are never deleted, so clearing an import leaves them, and the next
 * run matches them again.
 */
class UsersPass implements Pass
{
    public const TYPE = 'user';

    /** tkbtc's permission bit for an administrator. */
    private const PERM_ADMIN = 0x1;

    public function name(): string
    {
        return 'Users';
    }

    public function count(ImportContext $context): int
    {
        return $this->rows($context)->count();
    }

    public function run(ImportContext $context): int
    {
        $imported = 0;

        foreach ($this->rows($context) as $row) {
            if ($context->has(self::TYPE, $row->user_id)) {
                continue;
            }

            $email = Str::lower(trim((string) $row->email));

            if ($email === '') {
                $context->warn("tkbtc user {$row->user_id} has no email and was not imported.");

                continue;
            }

            if ($existing = User::where('email', $email)->first()) {
                $context->map(self::TYPE, $row->user_id, $existing->id, created: false);
                $context->note("{$email} is already a Nugget user and was left as it is.");

                continue;
            }

            if (! $row->active && ! $this->ownsAnything($context, (int) $row->user_id)) {
                $context->note("{$email} is inactive in tkbtc and owns nothing there, so it was not imported.");

                continue;
            }

            $context->map(self::TYPE, $row->user_id, $this->create($context, $row, $email)->id);
            $imported++;
        }

        return $imported;
    }

    public function clear(ImportContext $context): void
    {
        // Users are deactivated, never deleted. The map stays, so they are found again.
    }

    /**
     * @return Collection<int, object>
     */
    private function rows(ImportContext $context): Collection
    {
        return $context->source->db()->table('user')
            ->join('auth', fn ($join) => $join->on('auth.fid', '=', 'user.user_id')->where('auth.fkey', 'like', '%User'))
            ->orderBy('user.user_id')
            ->get(['user.user_id', 'user.given_name', 'user.family_name', 'auth.email', 'auth.password', 'auth.permissions', 'auth.timezone', 'auth.active']);
    }

    /**
     * Whether a tkbtc user has an exchange or an asset, which the later passes bring over to them.
     */
    private function ownsAnything(ImportContext $context, int $userId): bool
    {
        $tkbtc = $context->source->db();

        return $tkbtc->table('exchange')->where('user_id', $userId)->exists()
            || $tkbtc->table('asset')->where('user_id', $userId)->exists();
    }

    private function create(ImportContext $context, object $row, string $email): User
    {
        $name = trim("{$row->given_name} {$row->family_name}") ?: Str::before($email, '@');
        $canSignIn = $this->isBcrypt((string) $row->password);

        $user = new User(['name' => $name, 'email' => $email, 'password' => Str::password(40)]);
        $user->forceFill([
            'timezone' => in_array($row->timezone, DateTimeZone::listIdentifiers(), true) ? $row->timezone : User::DEFAULT_TIMEZONE,
            'role' => ((int) $row->permissions & self::PERM_ADMIN) ? Role::Admin : Role::Member,
            'active' => (bool) $row->active,
        ])->save();

        if ($canSignIn) {
            // Written as it is: the model would hash it again, or refuse its cost.
            DB::table('users')->where('id', $user->id)->update(['password' => $row->password]);
        } elseif ($user->active) {
            $context->warn("{$email} has a password Nugget cannot check, so it was not carried over. They need \"Forgot your password?\" to sign in.");
        }

        return $user;
    }

    private function isBcrypt(string $hash): bool
    {
        return strlen($hash) === 60 && preg_match('/^\$2[aby]\$\d\d\$/', $hash) === 1;
    }
}
