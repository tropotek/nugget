<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\BalanceMismatch;
use App\Notifications\Contracts\Presentable;
use App\Notifications\Presented;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Notifications\DatabaseNotification;

/**
 * A user's database notifications, for the top bar and the notifications page.
 */
class NotificationFeed
{
    /**
     * The user's notifications, the latest to change first. A balance
     * mismatch is updated in place, so that is not always the newest.
     *
     * @return MorphMany<DatabaseNotification, User>
     */
    public static function of(User $user): MorphMany
    {
        return $user->notifications()->reorder()->latest('updated_at')->latest('id');
    }

    /**
     * How a notification is shown. One of a type that does not say (a class
     * since removed) still gets a line and a place to go.
     */
    public static function present(DatabaseNotification $notification): Presented
    {
        $type = $notification->type;

        return is_subclass_of($type, Presentable::class)
            ? $type::present($notification->data)
            : new Presented('Notification', '', route('notifications.index'), 'ri-notification-3-line');
    }

    /**
     * Delete the read notifications that last changed more than this many
     * days ago. A balance mismatch is not one of them: it stands for
     * something still wrong, and goes when the balance and the ledger agree.
     */
    public static function prune(int $days): int
    {
        return DatabaseNotification::query()
            ->whereNotNull('read_at')
            ->where('updated_at', '<', now()->subDays($days))
            ->where('type', '!=', BalanceMismatch::class)
            ->delete();
    }
}
