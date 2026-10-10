<?php

namespace Tests\Fakes;

use App\Import\ImportContext;
use App\Import\Pass;
use App\Models\User;
use RuntimeException;

/**
 * An import pass for the importer's own tests: it brings each tkbtc user
 * over as a Nugget user, matching one that has the same email. It can be
 * told to fail, and records the order passes ran and cleared in.
 */
class NotesPass implements Pass
{
    /** @var list<string> */
    public static array $log = [];

    public function __construct(private readonly string $name = 'Users', private readonly bool $fails = false) {}

    public function name(): string
    {
        return $this->name;
    }

    public function count(ImportContext $context): int
    {
        return $context->source->db()->table('user')->count();
    }

    public function run(ImportContext $context): int
    {
        self::$log[] = "run {$this->name}";
        $imported = 0;

        foreach ($context->source->db()->table('user')->orderBy('user_id')->get() as $row) {
            if ($context->has($this->name, $row->user_id)) {
                continue;
            }

            $email = "{$this->name}-{$row->user_id}@example.com";
            $existing = User::where('email', strtolower($email))->first();
            $user = $existing ?? User::factory()->create(['name' => $row->given_name, 'email' => strtolower($email)]);

            $context->map($this->name, $row->user_id, $user->id, created: ! $existing);
            $imported += $existing ? 0 : 1;
        }

        if ($this->fails) {
            throw new RuntimeException("The {$this->name} pass broke.");
        }

        return $imported;
    }

    public function clear(ImportContext $context): void
    {
        self::$log[] = "clear {$this->name}";

        User::whereKey($context->created($this->name))->delete();
        $context->forget($this->name);
    }
}
