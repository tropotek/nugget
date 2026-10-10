<?php

namespace Tests\Feature;

use App\Facades\Settings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + ['site_name' => 'The Stack', 'parcel_matching' => 'lowest_gain', 'first_report_year' => '2023'];
    }

    public function test_a_guest_is_sent_to_the_login_screen(): void
    {
        $this->get('/settings')->assertRedirect(route('login'));
    }

    public function test_a_member_cannot_see_or_change_the_settings(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/settings')->assertForbidden();
        $this->put('/settings', $this->form())->assertForbidden();

        $this->assertSame('fifo', Settings::get('parcel_matching'));
    }

    public function test_the_sidebar_shows_settings_to_an_admin_only(): void
    {
        $this->actingAs($this->admin)->get('/')->assertSee('href="'.route('settings.edit').'"', false);

        $this->actingAs(User::factory()->create())->get('/')->assertDontSee('href="'.route('settings.edit').'"', false);
    }

    public function test_the_page_shows_the_current_settings(): void
    {
        $this->travelTo('2026-10-10');

        $this->actingAs($this->admin)
            ->get('/settings')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Settings</h4>', false)
            ->assertSee('name="site_name" value="'.config('app.name').'"', false)
            ->assertSee('<option value="fifo" selected>', false)
            ->assertSee('<option value="lowest_gain">', false)
            ->assertSee('<option value="2025" selected>2024–25</option>', false)
            ->assertSee('<option value="2027">2026–27</option>', false)
            ->assertDontSee('<option value="2028"', false);
    }

    public function test_an_admin_can_change_the_settings(): void
    {
        $this->actingAs($this->admin)
            ->put('/settings', $this->form())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('settings.edit'))
            ->assertSessionHas('success', 'Settings saved.');

        $this->assertSame('The Stack', Settings::get('site_name'));
        $this->assertSame('lowest_gain', Settings::get('parcel_matching'));
        $this->assertSame(2023, Settings::get('first_report_year'));
    }

    public function test_bad_values_are_refused(): void
    {
        $this->travelTo('2026-10-10');
        $this->actingAs($this->admin);

        $this->put('/settings', $this->form(['site_name' => '']))->assertSessionHasErrors('site_name');
        $this->put('/settings', $this->form(['parcel_matching' => 'lifo']))->assertSessionHasErrors('parcel_matching');
        $this->put('/settings', $this->form(['first_report_year' => 'last year']))->assertSessionHasErrors('first_report_year');
        $this->put('/settings', $this->form(['first_report_year' => '2028']))->assertSessionHasErrors('first_report_year');

        $this->assertSame(config('app.name'), Settings::get('site_name'));
        $this->assertSame('fifo', Settings::get('parcel_matching'));
    }
}
