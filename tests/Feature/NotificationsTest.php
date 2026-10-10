<?php

namespace Tests\Feature;

use App\Ledger\Holdings;
use App\Models\Asset;
use App\Models\Exchange;
use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Notifications\NewTrades;
use App\Support\Decimal;
use App\Support\NotificationFeed;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The top bar's bell, the notifications page and the pruning. The user is in
 * Melbourne, and "now" is midday there on 1 August 2025.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Exchange $exchange;

    private Asset $bitcoin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2025-08-01 02:00:00');

        $this->user = User::factory()->create(['timezone' => 'Australia/Melbourne']);
        $this->exchange = Exchange::factory()->for($this->user)->create(['label' => 'Main account']);
        $this->bitcoin = Asset::factory()->onExchange($this->exchange, 'BTC-AUD')->create(['name' => 'Bitcoin', 'unit' => 'BTC']);
        $this->actingAs($this->user);
    }

    private function trades(int $count = 3, ?User $user = null): DatabaseNotification
    {
        $user ??= $this->user;
        $before = $user->notifications()->pluck('id');
        $user->notify(new NewTrades($this->exchange, [['asset_id' => $this->bitcoin->id, 'asset' => 'Bitcoin', 'count' => $count]]));

        return $user->notifications()->whereNotIn('id', $before)->sole();
    }

    private function mismatch(string $onExchange = '0.25'): DatabaseNotification
    {
        $this->user->notify(new BalanceMismatch($this->exchange, app(Holdings::class)->forAsset($this->bitcoin), Decimal::of($onExchange)));

        return $this->user->notifications()->where('type', BalanceMismatch::class)->sole();
    }

    /**
     * Set when a notification last changed, and when it was read.
     */
    private function age(DatabaseNotification $notification, string $changed, ?string $read = null): void
    {
        DatabaseNotification::query()->whereKey($notification->id)->update(['updated_at' => $changed, 'read_at' => $read]);
    }

    // How each is shown

    public function test_new_trades_say_how_many_and_lead_to_them(): void
    {
        $this->user->notify(new NewTrades($this->exchange, [
            ['asset_id' => 7, 'asset' => 'Bitcoin', 'count' => 2],
            ['asset_id' => 9, 'asset' => 'Cardano', 'count' => 1],
        ]));

        $shown = NotificationFeed::present($this->user->notifications()->sole());

        $this->assertSame('3 new trades', $shown->title);
        $this->assertSame('On Main account: 2 Bitcoin, 1 Cardano.', $shown->text);
        $this->assertSame(route('transactions.index', ['source' => 'exchange']), $shown->url);

        $one = NotificationFeed::present($this->trades(1));

        $this->assertSame('1 new trade', $one->title);
        $this->assertSame(route('transactions.index', ['source' => 'exchange', 'asset' => $this->bitcoin->id]), $one->url);
    }

    public function test_a_balance_mismatch_says_which_way_and_leads_to_the_exchange(): void
    {
        $more = NotificationFeed::present($this->mismatch('0.25'));

        $this->assertSame('Bitcoin does not match Main account', $more->title);
        $this->assertSame('Main account has 0.25 BTC more than the ledger.', $more->text);
        $this->assertSame(route('exchanges.edit', $this->exchange).'#balance-mismatches', $more->url);

        $less = BalanceMismatch::present(['difference' => '-1.5', 'asset' => 'Cardano', 'unit' => 'ADA', 'exchange' => 'Main account', 'exchange_id' => 1]);

        $this->assertSame('Main account has 1.5 ADA less than the ledger.', $less->text);
    }

    public function test_a_notification_of_a_type_that_is_gone_still_shows(): void
    {
        $id = (string) Str::uuid();
        $this->user->notifications()->create(['id' => $id, 'type' => 'App\\Notifications\\Removed', 'data' => ['x' => 1]]);

        $this->get('/notifications')->assertOk()->assertSee('Notification');
        $this->get("/notifications/{$id}")->assertRedirect(route('notifications.index'));
    }

    // The bell

    public function test_the_top_bar_shows_the_number_unread_and_the_latest(): void
    {
        $read = $this->trades(1);
        $this->age($read, '2025-07-31 02:00:00', '2025-07-31 03:00:00');
        $unread = $this->trades(3);
        $this->age($unread, '2025-08-01 01:55:00');
        $this->mismatch();

        $this->get('/assets')
            ->assertOk()
            ->assertSeeInOrder([
                'id="topbar-notifications"',
                'aria-label="Notifications, 2 unread"',
                'noti-icon-badge">2<',
                'Mark all read',
                'Bitcoin does not match Main account', 'Main account has 0.25 BTC more than the ledger.',
                '3 new trades', '5 minutes ago',
                '1 new trade', '1 day ago',
                'All notifications',
            ], false)
            ->assertSee('href="'.route('notifications.show', $unread).'" class="dropdown-item notify-item active"', false)
            ->assertSee('href="'.route('notifications.show', $read).'" class="dropdown-item notify-item"', false);
    }

    public function test_the_bell_has_no_badge_when_nothing_is_unread(): void
    {
        $this->get('/assets')
            ->assertSee('id="topbar-notifications"', false)
            ->assertSee('Nothing yet.')
            ->assertDontSee('noti-icon-badge', false)
            ->assertDontSee('Mark all read');
    }

    public function test_the_bell_carries_what_the_browser_notification_needs(): void
    {
        $unread = $this->trades(3);
        $this->age($unread, '2025-08-01 01:55:00');

        $this->get('/assets')
            ->assertSee('data-now="'.now()->getTimestampMs().'"', false)
            ->assertSee('data-at="'.now()->subMinutes(5)->getTimestampMs().'" data-title="3 new trades" data-text="On Main account: 3 Bitcoin."', false)
            ->assertSee('data-unread', false)
            ->assertSee('data-notifications-enable hidden', false);
    }

    public function test_the_bell_shows_only_the_users_own(): void
    {
        $this->trades(4, User::factory()->create());

        $this->get('/assets')->assertDontSee('4 new trades')->assertDontSee('noti-icon-badge', false);
    }

    public function test_htmx_is_sent_the_bell_alone(): void
    {
        $this->trades(3);

        $this->get('/notifications/menu')
            ->assertOk()
            ->assertSee('id="topbar-notifications"', false)
            ->assertSee('hx-get="'.route('notifications.menu').'"', false)
            ->assertSee('3 new trades')
            ->assertDontSee('<html', false)
            ->assertDontSee('navbar-custom', false);
    }

    public function test_a_guest_has_no_bell_and_no_notifications(): void
    {
        auth()->logout();

        $this->get('/login')->assertOk()->assertDontSee('topbar-notifications');
        $this->get('/notifications')->assertRedirect(route('login'));
        $this->get('/notifications/menu')->assertRedirect(route('login'));
    }

    // Reading

    public function test_opening_a_notification_marks_it_read_and_goes_where_it_leads(): void
    {
        $notification = $this->trades(1);

        $this->get(route('notifications.show', $notification))
            ->assertRedirect(route('transactions.index', ['source' => 'exchange', 'asset' => $this->bitcoin->id]));

        $this->assertNotNull($notification->refresh()->read_at);
    }

    public function test_another_users_notification_cannot_be_opened(): void
    {
        $theirs = $this->trades(1, User::factory()->create());

        $this->get(route('notifications.show', $theirs))->assertNotFound();

        $this->assertNull($theirs->refresh()->read_at);
    }

    public function test_mark_all_read_reads_the_users_own_and_no_one_elses(): void
    {
        $mine = $this->trades(1);
        $this->mismatch();
        $theirs = $this->trades(1, User::factory()->create());

        $this->from('/assets')->post(route('notifications.read-all'))->assertRedirect('/assets');

        $this->assertSame(0, $this->user->unreadNotifications()->count());
        $this->assertNotNull($mine->refresh()->read_at);
        $this->assertNull($theirs->refresh()->read_at);
    }

    // The page

    public function test_the_page_lists_every_notification_the_latest_to_change_first(): void
    {
        $old = $this->trades(1);
        $this->age($old, '2025-07-20 02:00:00', '2025-07-20 03:00:00');
        $new = $this->trades(3);
        $this->age($new, '2025-08-01 01:55:00');

        $this->get('/notifications')
            ->assertOk()
            ->assertSee('<h4 class="page-title">Notifications</h4>', false)
            ->assertSeeInOrder(['3 new trades', 'New', 'On Main account: 3 Bitcoin.', '1 Aug 2025, 11:55am', '1 new trade', '20 Jul 2025, 12:00pm'])
            ->assertSee('href="'.route('notifications.show', $new).'"', false)
            ->assertSee('A read notification is deleted after 30 days.');
    }

    public function test_an_empty_page_says_so(): void
    {
        $this->get('/notifications')->assertOk()->assertSee('No notifications.');
    }

    public function test_the_page_is_paged(): void
    {
        foreach (range(1, 27) as $count) {
            $this->trades($count);
        }

        $this->get('/notifications')->assertViewHas('notifications', fn ($page) => $page->count() === 25 && $page->total() === 27);
        $this->get('/notifications?page=2')->assertViewHas('notifications', fn ($page) => $page->count() === 2);
    }

    // Pruning

    public function test_read_notifications_older_than_30_days_are_pruned(): void
    {
        $oldRead = $this->trades(1);
        $this->age($oldRead, '2025-07-01 01:00:00', '2025-07-01 02:00:00');
        $justInside = $this->trades(2);
        $this->age($justInside, '2025-07-02 02:00:00', '2025-07-02 03:00:00');
        $oldUnread = $this->trades(3);
        $this->age($oldUnread, '2025-06-01 02:00:00');
        $oldMismatch = $this->mismatch();
        $this->age($oldMismatch, '2025-06-01 02:00:00', '2025-06-01 03:00:00');

        $this->artisan('nugget:prune-notifications')
            ->expectsOutputToContain('Deleted 1 read notifications older than 30 days.')
            ->assertSuccessful();

        $this->assertModelMissing($oldRead);
        $this->assertModelExists($justInside);
        $this->assertModelExists($oldUnread);
        // A mismatch stays until the balance and the ledger agree.
        $this->assertModelExists($oldMismatch);
    }

    public function test_the_days_kept_come_from_config(): void
    {
        config(['nugget.notifications.keep_read_days' => 7]);
        $read = $this->trades(1);
        $this->age($read, '2025-07-20 02:00:00', '2025-07-20 03:00:00');

        $this->artisan('nugget:prune-notifications')->expectsOutputToContain('Deleted 1 read notifications older than 7 days.');

        $this->assertModelMissing($read);
    }

    public function test_the_pruning_is_scheduled_nightly_without_overlapping(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->sole(fn ($event) => str_contains($event->command, 'nugget:prune-notifications'));

        $this->assertSame('25 3 * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }
}
