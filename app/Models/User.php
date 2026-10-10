<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * role and active are deliberately not fillable: set them explicitly.
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** The timezone a new user's dates are shown in. Also the column default in the users migration. */
    public const DEFAULT_TIMEZONE = 'Australia/Melbourne';

    /** @var array<string, mixed> */
    protected $attributes = [
        'role' => 'member',
        'active' => true,
        'timezone' => self::DEFAULT_TIMEZONE,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => Role::class,
            'active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return HasMany<Exchange, $this>
     */
    public function exchanges(): HasMany
    {
        return $this->hasMany(Exchange::class);
    }

    /**
     * What the user has entered for each year's tax report.
     *
     * @return HasMany<TaxYear, $this>
     */
    public function taxYears(): HasMany
    {
        return $this->hasMany(TaxYear::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }
}
