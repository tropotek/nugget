<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\LocalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_date_is_moved_to_the_signed_in_users_timezone(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Perth']));

        $local = LocalTime::of(Carbon::parse('2025-06-30 18:30:00', 'UTC'));

        $this->assertSame('2025-07-01 02:30 +08:00', $local->format('Y-m-d H:i P'));
    }

    public function test_a_named_user_takes_the_place_of_the_signed_in_one(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Perth']));
        $other = User::factory()->create(['timezone' => 'Europe/London']);

        $local = LocalTime::of(Carbon::parse('2025-06-30 18:30:00', 'UTC'), $other);

        $this->assertSame('2025-06-30 19:30 +01:00', $local->format('Y-m-d H:i P'));
    }

    public function test_with_nobody_signed_in_the_default_timezone_is_used(): void
    {
        $local = LocalTime::of(Carbon::parse('2025-06-30 18:30:00', 'UTC'));

        $this->assertSame(User::DEFAULT_TIMEZONE, $local->timezoneName);
    }

    public function test_the_date_component_shows_the_time_in_the_users_timezone(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Perth']));

        $html = Blade::render('<x-date :value="$date" />', ['date' => Carbon::parse('2025-06-30 18:30:00', 'UTC')]);

        $this->assertSame('<time datetime="2025-07-01T02:30:00+08:00">1 Jul 2025, 2:30am</time>', trim($html));
    }

    public function test_the_date_component_takes_a_format(): void
    {
        $this->actingAs(User::factory()->create(['timezone' => 'Australia/Perth']));

        $html = Blade::render('<x-date :value="$date" format="j M Y" />', ['date' => Carbon::parse('2025-06-30 18:30:00', 'UTC')]);

        $this->assertStringContainsString('>1 Jul 2025<', $html);
    }

    public function test_the_date_component_shows_nothing_for_no_date(): void
    {
        $this->assertSame('', trim(Blade::render('<x-date :value="null" />')));
    }
}
