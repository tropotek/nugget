<?php

namespace Tests\Feature;

use App\Support\Navigation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/nav/home', fn () => app(Navigation::class)->items())->name('home');
        Route::get('/nav/things', fn () => app(Navigation::class)->items())->name('things.index');
        Route::get('/nav/things/{id}', fn () => app(Navigation::class)->items())->name('things.show');
        Route::get('/nav/staff', fn () => app(Navigation::class)->items())->name('staff.index');

        // Routes added after boot are not found by name until the lookup is rebuilt.
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_an_item_gets_its_label_icon_and_url(): void
    {
        config(['navigation' => [
            ['label' => 'Home', 'icon' => 'ri-home-line', 'route' => 'home'],
        ]]);

        $this->get('/nav/things')->assertExactJson([
            ['label' => 'Home', 'icon' => 'ri-home-line', 'url' => url('/nav/home'), 'active' => false],
        ]);
    }

    public function test_an_item_whose_route_does_not_exist_is_left_out(): void
    {
        config(['navigation' => [
            ['label' => 'Home', 'icon' => 'ri-home-line', 'route' => 'home'],
            ['label' => 'Later', 'icon' => 'ri-time-line', 'route' => 'not.built.yet'],
        ]]);

        $this->assertSame(['Home'], $this->labels('/nav/home'));
    }

    public function test_the_item_for_the_current_route_is_active(): void
    {
        config(['navigation' => [
            ['label' => 'Home', 'icon' => 'ri-home-line', 'route' => 'home'],
            ['label' => 'Things', 'icon' => 'ri-box-line', 'route' => 'things.index'],
        ]]);

        $this->assertSame(['Home' => true, 'Things' => false], $this->active('/nav/home'));
        $this->assertSame(['Home' => false, 'Things' => true], $this->active('/nav/things'));
    }

    public function test_an_item_is_active_on_the_other_routes_in_its_group(): void
    {
        config(['navigation' => [
            ['label' => 'Things', 'icon' => 'ri-box-line', 'route' => 'things.index'],
        ]]);

        $this->assertSame(['Things' => true], $this->active('/nav/things/7'));
    }

    public function test_an_item_can_name_the_routes_it_is_active_on(): void
    {
        config(['navigation' => [
            ['label' => 'Home', 'icon' => 'ri-home-line', 'route' => 'home', 'active' => ['home', 'staff.*']],
        ]]);

        $this->assertSame(['Home' => true], $this->active('/nav/staff'));
        $this->assertSame(['Home' => false], $this->active('/nav/things'));
    }

    public function test_an_item_with_an_ability_shows_only_when_the_gate_allows_it(): void
    {
        config(['navigation' => [
            ['label' => 'Staff', 'icon' => 'ri-team-line', 'route' => 'staff.index', 'can' => 'see-staff'],
        ]]);

        Gate::define('see-staff', fn ($user = null) => false);
        $this->assertSame([], $this->labels('/nav/home'));

        Gate::define('see-staff', fn ($user = null) => true);
        $this->assertSame(['Staff'], $this->labels('/nav/home'));
    }

    public function test_a_heading_shows_only_when_an_item_follows_it(): void
    {
        config(['navigation' => [
            ['title' => 'Main'],
            ['label' => 'Home', 'icon' => 'ri-home-line', 'route' => 'home'],
            ['title' => 'Empty'],
            ['label' => 'Later', 'icon' => 'ri-time-line', 'route' => 'not.built.yet'],
            ['title' => 'Also empty'],
        ]]);

        $this->get('/nav/things')->assertExactJson([
            ['title' => 'Main'],
            ['label' => 'Home', 'icon' => 'ri-home-line', 'url' => url('/nav/home'), 'active' => false],
        ]);
    }

    /** @return list<string> */
    private function labels(string $uri): array
    {
        return array_keys($this->active($uri));
    }

    /** @return array<string, bool> */
    private function active(string $uri): array
    {
        return collect($this->get($uri)->json())
            ->filter(fn (array $item) => isset($item['label']))
            ->mapWithKeys(fn (array $item) => [$item['label'] => $item['active']])
            ->all();
    }
}
