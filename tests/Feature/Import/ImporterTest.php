<?php

namespace Tests\Feature\Import;

use App\Import\ImportContext;
use App\Import\Importer;
use App\Import\TkbtcSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Fakes\NotesPass;
use Tests\TestCase;

/**
 * The importer's own behaviour, with a made-up tkbtc database of three users
 * and a stand-in pass that brings them over.
 */
class ImporterTest extends TestCase
{
    use RefreshDatabase;
    use TkbtcDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpTkbtc()->table('user')->insert([
            ['user_id' => 1, 'given_name' => 'Ann'],
            ['user_id' => 2, 'given_name' => 'Bob'],
            ['user_id' => 5, 'given_name' => 'Cat'],
        ]);

        NotesPass::$log = [];
    }

    private function context(bool $dryRun = false): ImportContext
    {
        return new ImportContext(app(TkbtcSource::class), $dryRun);
    }

    /**
     * Use these passes for the command.
     *
     * @param  list<NotesPass>  $passes
     */
    private function withPasses(array $passes): void
    {
        $this->app->bind(Importer::class, fn () => new Importer($passes));
    }

    // The source

    public function test_the_source_is_the_tkbtc_connection_and_counts_its_tables(): void
    {
        $source = app(TkbtcSource::class);

        $source->connect();

        $this->assertSame(['user' => 3, 'auth' => 0, 'exchange' => 0, 'market' => 0, 'asset' => 0, 'asset_log' => 0, 'tick' => 0], $source->counts());
        $this->assertSame('sqlite :memory:', $source->describe());
    }

    public function test_a_database_that_is_not_tkbtc_is_refused(): void
    {
        $this->tkbtc()->statement('DROP TABLE asset_log');
        $this->tkbtc()->statement('DROP TABLE tick');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('This is not a tkbtc database: it has no asset_log, tick table.');

        app(TkbtcSource::class)->connect();
    }

    public function test_the_connection_is_set_by_the_tkbtc_db_variables(): void
    {
        $config = file_get_contents(config_path('database.php'));

        foreach (['TKBTC_DB_CONNECTION', 'TKBTC_DB_HOST', 'TKBTC_DB_PORT', 'TKBTC_DB_DATABASE', 'TKBTC_DB_USERNAME', 'TKBTC_DB_PASSWORD'] as $variable) {
            $this->assertStringContainsString("env('{$variable}'", $config);
        }

        // Under test it is never the real one, whatever .env says.
        $this->assertSame('sqlite', config('database.connections.tkbtc.driver'));
        $this->assertStringContainsString('TKBTC_DB_HOST', file_get_contents(base_path('.env.example')));
    }

    // The map

    public function test_the_map_remembers_what_a_tkbtc_row_became(): void
    {
        $context = $this->context();

        $this->assertNull($context->newId('asset', 7));
        $this->assertFalse($context->has('asset', 7));

        $context->map('asset', 7, 42);
        $context->map('user', 7, 3, created: false);

        $this->assertSame(42, $context->newId('asset', 7));
        $this->assertSame(42, $context->newId('asset', '7'));
        $this->assertSame(3, $context->newId('user', 7));
        $this->assertNull($context->newId('asset', null));

        // A later run reads it back from the table.
        $later = $this->context();
        $this->assertSame(42, $later->newId('asset', 7));
        $this->assertSame([42], $later->created('asset'));
        $this->assertSame([], $later->created('user'));

        $later->forget('asset');
        $this->assertNull($this->context()->newId('asset', 7));
        $this->assertSame(3, $this->context()->newId('user', 7));
    }

    public function test_mapping_a_row_again_replaces_what_it_became(): void
    {
        $context = $this->context();
        $context->map('asset', 7, 42);
        $context->map('asset', 7, 43);

        $this->assertSame(43, $this->context()->newId('asset', 7));
        $this->assertSame(1, DB::table('import_ids')->count());
    }

    public function test_a_dry_run_keeps_its_map_to_itself(): void
    {
        $context = $this->context(dryRun: true);
        $context->map('asset', 7, 42);

        $this->assertSame(42, $context->newId('asset', 7));
        $this->assertSame(0, DB::table('import_ids')->count());
    }

    // Running

    public function test_the_passes_run_in_order_and_report_what_they_found_and_brought_over(): void
    {
        $report = (new Importer([new NotesPass('Users'), new NotesPass('Others')]))->run($this->context());

        $this->assertSame(['Users' => ['count' => 3, 'imported' => 3], 'Others' => ['count' => 3, 'imported' => 3]], $report);
        $this->assertSame(['run Users', 'run Others'], NotesPass::$log);
        $this->assertSame(6, User::count());
        $this->assertSame(6, DB::table('import_ids')->count());
    }

    public function test_a_dry_run_counts_and_writes_nothing(): void
    {
        $report = (new Importer([new NotesPass]))->run($this->context(dryRun: true));

        $this->assertSame(['Users' => ['count' => 3, 'imported' => null]], $report);
        $this->assertSame([], NotesPass::$log);
        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('import_ids')->count());
    }

    public function test_running_again_adds_nothing_twice_and_brings_over_what_is_new(): void
    {
        $importer = new Importer([new NotesPass]);
        $importer->run($this->context());

        $again = $importer->run($this->context());

        $this->assertSame(['Users' => ['count' => 3, 'imported' => 0]], $again);
        $this->assertSame(3, User::count());

        $this->tkbtc()->table('user')->insert(['user_id' => 9, 'given_name' => 'Dee']);

        $this->assertSame(['Users' => ['count' => 4, 'imported' => 1]], $importer->run($this->context()));
        $this->assertSame(4, User::count());
    }

    public function test_a_fresh_run_clears_what_was_imported_first_the_last_pass_before_the_first(): void
    {
        $importer = new Importer([new NotesPass('Users'), new NotesPass('Others')]);
        $importer->run($this->context());
        $imported = User::pluck('id')->all();
        $mine = User::factory()->create(['email' => 'mine@example.com']);
        NotesPass::$log = [];

        $report = $importer->run($this->context(), fresh: true);

        $this->assertSame(['clear Others', 'clear Users', 'run Users', 'run Others'], NotesPass::$log);
        $this->assertSame(['Users' => ['count' => 3, 'imported' => 3], 'Others' => ['count' => 3, 'imported' => 3]], $report);
        // The six are new rows; the user that was never imported is untouched.
        $this->assertSame(7, User::count());
        $this->assertSame(0, User::whereKey($imported)->count());
        $this->assertModelExists($mine);
    }

    public function test_clearing_leaves_a_row_the_import_only_matched(): void
    {
        $already = User::factory()->create(['email' => 'users-2@example.com', 'name' => 'Already here']);
        $importer = new Importer([new NotesPass]);

        $this->assertSame(['Users' => ['count' => 3, 'imported' => 2]], $importer->run($this->context()));
        $this->assertSame($already->id, $this->context()->newId('Users', 2));

        $importer->run($this->context(), fresh: true);

        $this->assertModelExists($already);
        $this->assertSame('Already here', $already->refresh()->name);
        $this->assertSame(3, User::count());
    }

    public function test_a_pass_that_fails_leaves_nothing_half_imported(): void
    {
        $importer = new Importer([new NotesPass('Users'), new NotesPass('Others', fails: true)]);

        try {
            $importer->run($this->context());
            $this->fail('It should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('The Others pass broke.', $e->getMessage());
        }

        $this->assertSame(0, User::count());
        $this->assertSame(0, DB::table('import_ids')->count());
    }

    public function test_a_fresh_run_that_fails_keeps_the_earlier_import(): void
    {
        (new Importer([new NotesPass]))->run($this->context());
        $before = User::pluck('id')->all();

        try {
            (new Importer([new NotesPass('Users', fails: true)]))->run($this->context(), fresh: true);
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame($before, User::pluck('id')->all());
        $this->assertSame(3, DB::table('import_ids')->count());
    }

    public function test_nothing_is_written_to_the_tkbtc_database(): void
    {
        $before = app(TkbtcSource::class)->counts();

        (new Importer([new NotesPass]))->run($this->context(), fresh: true);

        $this->assertSame($before, app(TkbtcSource::class)->counts());
    }

    // The command

    public function test_the_command_shows_the_source_and_what_each_pass_brought_over(): void
    {
        $this->withPasses([new NotesPass]);

        $this->artisan('nugget:import-tkbtc')
            ->expectsOutputToContain('tkbtc source: sqlite :memory:')
            ->expectsTable(['Table', 'Rows'], [['user', 3], ['auth', 0], ['exchange', 0], ['market', 0], ['asset', 0], ['asset_log', 0], ['tick', 0]])
            ->expectsTable(['Pass', 'In tkbtc', 'Imported'], [['Users', 3, 3]])
            ->expectsOutputToContain('Import finished.')
            ->assertSuccessful();

        $this->assertSame(3, User::count());
    }

    public function test_the_commands_dry_run_reports_counts_and_changes_nothing(): void
    {
        $this->withPasses([new NotesPass]);

        $this->artisan('nugget:import-tkbtc', ['--dry-run' => true])
            ->expectsTable(['Pass', 'In tkbtc', 'Imported (dry run)'], [['Users', 3, '—']])
            ->expectsOutputToContain('Dry run: nothing was changed.')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_the_commands_fresh_run_replaces_the_earlier_import(): void
    {
        $this->withPasses([new NotesPass]);
        $this->artisan('nugget:import-tkbtc')->assertSuccessful();
        $first = User::pluck('id')->all();

        $this->artisan('nugget:import-tkbtc')->expectsTable(['Pass', 'In tkbtc', 'Imported'], [['Users', 3, 0]])->assertSuccessful();
        $this->assertSame($first, User::pluck('id')->all());

        $this->artisan('nugget:import-tkbtc', ['--fresh' => true])->expectsTable(['Pass', 'In tkbtc', 'Imported'], [['Users', 3, 3]])->assertSuccessful();
        $this->assertSame(3, User::count());
        $this->assertSame(0, User::whereKey($first)->count());
    }

    public function test_the_command_fails_when_the_source_cannot_be_read_or_is_not_tkbtc(): void
    {
        $this->tkbtc()->statement('DROP TABLE tick');

        $this->artisan('nugget:import-tkbtc')
            ->expectsOutputToContain('The tkbtc database could not be read: This is not a tkbtc database: it has no tick table.')
            ->expectsOutputToContain('TKBTC_DB_HOST')
            ->assertFailed();
    }

    public function test_the_command_says_when_a_pass_fails_and_that_nothing_changed(): void
    {
        $this->withPasses([new NotesPass('Users', fails: true)]);

        $this->artisan('nugget:import-tkbtc')
            ->expectsOutputToContain('The import failed and nothing was changed: The Users pass broke.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
