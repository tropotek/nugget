<?php

namespace Tests\Feature\Import;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\SeededData;
use App\Import\TkbtcSource;
use App\Ledger\Holdings;
use App\Ledger\ParcelMatcher;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\NotesPass;
use Tests\TestCase;

/**
 * An import of real data drops the made-up development data first.
 */
class SeededDataTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    /** The tables that hold something a seeded user owns. */
    private const OWNED = ['exchanges', 'exchange_balances', 'assets', 'transactions', 'parcels', 'disposals', 'prices', 'tax_years', 'notifications'];

    private User $real;

    private Asset $realAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc();

        // A real user with a small ledger of their own, there before the seed data.
        $this->real = User::factory()->create(['email' => 'pat@tropotek.test']);
        $exchange = Exchange::factory()->for($this->real)->create();
        $this->realAsset = Asset::factory()->onExchange($exchange, 'BTC-AUD')->create();
        Transaction::factory()->for($this->realAsset)->buy('2', '100')->create(['occurred_at' => '2024-08-01 02:00:00']);
        Transaction::factory()->for($this->realAsset)->sell('1', '150')->create(['occurred_at' => '2024-09-01 02:00:00']);
        app(ParcelMatcher::class)->rematch($this->realAsset);
        $this->real->taxYears()->create(['year' => 2025, 'losses_carried_forward' => '10']);
        $this->real->createToken('Claude', ['read']);

        $this->app->make(DatabaseSeeder::class)->__invoke();
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return collect(self::OWNED)->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])->all();
    }

    public function test_the_seeded_users_and_all_they_own_are_dropped_and_nobody_elses(): void
    {
        $this->assertSame(4, User::count());
        User::where('email', 'admin@example.com')->sole()->createToken('Demo', ['read']);

        $dropped = app(SeededData::class)->drop();

        $this->assertSame(3, $dropped);
        $this->assertSame(['pat@tropotek.test'], User::pluck('email')->all());
        // What is left is the real user's alone.
        $this->assertSame([
            'exchanges' => 1, 'exchange_balances' => 0, 'assets' => 1, 'transactions' => 2, 'parcels' => 1,
            'disposals' => 1, 'prices' => 0, 'tax_years' => 1, 'notifications' => 0,
        ], $this->counts());
        $this->assertSame(1, DB::table('personal_access_tokens')->count());
        $this->assertSame('1', (string) app(Holdings::class)->forAsset($this->realAsset)->units);
    }

    public function test_dropping_again_finds_nothing(): void
    {
        app(SeededData::class)->drop();

        $this->assertSame(0, app(SeededData::class)->drop());
        $this->assertSame(1, User::count());
    }

    public function test_the_seeded_admin_is_whoever_the_config_names(): void
    {
        config(['nugget.dev.admin.email' => 'Somebody@Else.test']);

        $this->assertSame(['somebody@else.test', 'member@example.com', 'inactive@example.com'], DatabaseSeeder::emails());

        // The admin seeded under the old name is no longer counted as seed data.
        $this->assertSame(2, app(SeededData::class)->drop());
        $this->assertTrue(User::where('email', 'admin@example.com')->exists());
    }

    public function test_nothing_is_dropped_in_production(): void
    {
        $before = User::count();
        $this->app['env'] = 'production';

        $this->assertSame(0, app(SeededData::class)->drop());
        $this->assertSame($before, User::count());
    }

    public function test_an_import_drops_the_seed_data_and_says_so(): void
    {
        $context = new ImportContext(app(TkbtcSource::class));

        (new Importer([new NotesPass]))->run($context);

        $this->assertSame(['pat@tropotek.test'], User::pluck('email')->all());
        $this->assertContains('Dropped the seeded development data: 3 made-up users and all they owned.', $context->notes);
    }

    public function test_a_dry_run_drops_nothing(): void
    {
        (new Importer([new NotesPass]))->run(new ImportContext(app(TkbtcSource::class), dryRun: true));

        $this->assertSame(4, User::count());
    }

    public function test_an_import_that_fails_keeps_the_seed_data(): void
    {
        try {
            (new Importer([new NotesPass('Users', fails: true)]))->run(new ImportContext(app(TkbtcSource::class)));
        } catch (\RuntimeException) {
            // Expected.
        }

        $this->assertSame(4, User::count());
    }

    public function test_a_user_the_import_itself_made_is_not_seed_data_whatever_its_email(): void
    {
        app(SeededData::class)->drop();
        // tkbtc has an admin@example.com of its own, and the import has brought it over.
        $theirs = User::factory()->create(['email' => 'admin@example.com']);
        (new ImportContext(app(TkbtcSource::class)))->map('user', 1, $theirs->id);

        $this->assertSame(0, app(SeededData::class)->drop());
        $this->assertModelExists($theirs);
    }

    public function test_a_tkbtc_user_once_matched_to_a_seeded_one_is_imported_afresh(): void
    {
        $seeded = User::where('email', 'admin@example.com')->sole();
        (new ImportContext(app(TkbtcSource::class)))->map('user', 1, $seeded->id, created: false);

        app(SeededData::class)->drop();

        $this->assertNull((new ImportContext(app(TkbtcSource::class)))->newId('user', 1));
    }
}
