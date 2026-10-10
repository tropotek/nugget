<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_the_app_layout_shows_the_shell_around_the_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('<title>Dashboard | '.config('app.name').'</title>', false)
            ->assertSee('class="navbar-custom"', false)
            ->assertSee('class="left-side-menu"', false)
            ->assertSee('<footer class="footer">', false)
            ->assertSee('<h4 class="page-title">Dashboard</h4>', false);
    }

    public function test_the_sidebar_marks_the_current_page(): void
    {
        $this->get('/')
            ->assertSeeInOrder(['<li class="menuitem-active">', 'class="active"', 'Dashboard'], false);
    }

    public function test_the_top_bar_has_the_light_and_dark_switch(): void
    {
        $this->get('/')->assertSee('id="light-dark-mode"', false);
    }

    public function test_flash_messages_are_shown(): void
    {
        $this->withSession(['success' => 'Transaction saved.', 'error' => 'Sync failed.'])
            ->get('/')
            ->assertSeeInOrder(['alert-success', 'Transaction saved.'], false)
            ->assertSeeInOrder(['alert-danger', 'Sync failed.'], false);
    }

    public function test_a_status_message_is_shown_as_a_success(): void
    {
        $this->withSession(['status' => 'Password updated.'])
            ->get('/')
            ->assertSeeInOrder(['alert-success', 'Password updated.'], false);
    }

    public function test_no_alert_is_shown_without_a_flash_message(): void
    {
        $this->get('/')->assertDontSee('alert-dismissible', false);
    }

    public function test_the_guest_layout_wraps_the_page_in_a_card_without_the_shell(): void
    {
        $this->blade('<x-layouts.guest title="Log in"><form id="login"></form></x-layouts.guest>')
            ->assertSee('<title>Log in | '.config('app.name').'</title>', false)
            ->assertSee('account-pages', false)
            ->assertSee('<form id="login"></form>', false)
            ->assertDontSee('left-side-menu', false)
            ->assertDontSee('navbar-custom', false);
    }
}
