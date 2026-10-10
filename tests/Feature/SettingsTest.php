<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_settings_table_has_the_expected_columns(): void
    {
        $this->assertEqualsCanonicalizing(['key', 'value', 'updated_at'], Schema::getColumnListing('settings'));
    }

    public function test_a_setting_that_is_not_stored_has_its_default(): void
    {
        $this->assertSame(config('app.name'), Settings::get('site_name'));
        $this->assertSame('fifo', Settings::get('parcel_matching'));
        $this->assertSame(2025, Settings::get('first_report_year'));
    }

    public function test_every_setting_is_listed_with_its_value(): void
    {
        Settings::set('parcel_matching', 'lowest_gain');

        $this->assertSame(
            ['site_name' => config('app.name'), 'parcel_matching' => 'lowest_gain', 'first_report_year' => 2025],
            Settings::all(),
        );
    }

    public function test_a_stored_setting_replaces_the_default(): void
    {
        Settings::set('site_name', 'The Stack');

        $this->assertSame('The Stack', Settings::get('site_name'));
        $this->assertSame('"The Stack"', DB::table('settings')->where('key', 'site_name')->value('value'));
    }

    public function test_a_setting_is_stored_once_however_often_it_is_set(): void
    {
        Settings::set('site_name', 'One');
        Settings::set('site_name', 'Two');

        $this->assertSame(1, DB::table('settings')->count());
        $this->assertSame('Two', Settings::get('site_name'));
    }

    public function test_a_number_comes_back_as_a_number(): void
    {
        Settings::set('first_report_year', '2023');

        $this->assertSame(2023, Settings::get('first_report_year'));
    }

    public function test_an_unknown_setting_is_refused(): void
    {
        try {
            Settings::get('site_nmae');
            $this->fail('Reading an unknown setting did not throw.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        Settings::set('site_nmae', 'Oops');
    }

    public function test_settings_are_read_from_the_cache_until_one_is_set(): void
    {
        Settings::set('site_name', 'Cached');
        Settings::get('site_name');

        DB::enableQueryLog();
        Settings::get('site_name');
        Settings::get('parcel_matching');
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();

        DB::table('settings')->where('key', 'site_name')->update(['value' => '"Behind the cache"']);
        $this->assertSame('Cached', Settings::get('site_name'));

        Settings::set('parcel_matching', 'lowest_gain');
        $this->assertSame('Behind the cache', Settings::get('site_name'));
    }

    public function test_the_site_name_setting_names_the_site(): void
    {
        Settings::set('site_name', 'The Stack');

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertSee('<title>Dashboard | The Stack</title>', false);

        $this->post('/logout');
        $this->get('/login')->assertSee('| The Stack</title>', false);
    }

    public function test_the_health_check_does_not_need_the_settings_table(): void
    {
        Schema::drop('settings');

        $this->get('/up')->assertOk();
    }
}
