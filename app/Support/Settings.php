<?php

namespace App\Support;

use App\Enums\ParcelMatching;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The settings an admin can change. Each one's default, type and validation
 * rules are defined here, and a value stored in the settings table replaces
 * the default. Read and write them through the Settings facade.
 */
class Settings
{
    private const CACHE_KEY = 'nugget:settings';

    /** The earliest financial year that can be chosen as the first one reported on. */
    public const EARLIEST_YEAR = 2010;

    /**
     * A financial year is named by the year it ends in: 2025 is 2024–25.
     *
     * @return array<string, array{default: mixed, type: 'string'|'int', rules: array<int, mixed>}>
     */
    public function definitions(): array
    {
        return [
            'site_name' => [
                'default' => config('app.name'),
                'type' => 'string',
                'rules' => ['required', 'string', 'max:100'],
            ],
            'parcel_matching' => [
                'default' => ParcelMatching::Fifo->value,
                'type' => 'string',
                'rules' => ['required', Rule::enum(ParcelMatching::class)],
            ],
            'first_report_year' => [
                'default' => 2025,
                'type' => 'int',
                'rules' => ['required', 'integer', 'between:'.self::EARLIEST_YEAR.','.$this->currentYear()],
            ],
        ];
    }

    /**
     * Every setting, with a stored value in place of its default.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $stored = Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('settings')->pluck('value', 'key')
            ->map(fn (?string $json) => json_decode((string) $json, true))
            ->all());

        $defaults = array_map(fn (array $definition) => $definition['default'], $this->definitions());

        return array_merge($defaults, array_intersect_key($stored, $defaults));
    }

    public function get(string $key): mixed
    {
        $this->definition($key);

        return $this->all()[$key];
    }

    public function set(string $key, mixed $value): void
    {
        $value = $this->definition($key)['type'] === 'int' ? (int) $value : (string) $value;

        DB::table('settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'updated_at' => now()],
        );

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The financial year in progress, which runs from 1 July.
     */
    public function currentYear(): int
    {
        $now = now(User::DEFAULT_TIMEZONE);

        return $now->month >= 7 ? $now->year + 1 : $now->year;
    }

    /**
     * @return array{default: mixed, type: 'string'|'int', rules: array<int, mixed>}
     */
    private function definition(string $key): array
    {
        return $this->definitions()[$key] ?? throw new InvalidArgumentException("There is no setting named '{$key}'.");
    }
}
