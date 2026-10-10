{{--
    The top bar's bell: the number unread and the latest notifications. It asks htmx for itself
    every minute (GET notifications.menu), unless its list is open. A click on one marks it read
    and goes where it leads.

    resources/js/notifications.js reads the data attributes to raise a browser notification for
    what is new: data-at is when a notification last changed and data-now the server's clock, in
    milliseconds.
--}}
@php
    $user = auth()->user();
    $latest = App\Support\NotificationFeed::of($user)->limit(8)->get();
    $unread = $user->unreadNotifications()->count();
@endphp

<li class="dropdown notification-list topbar-dropdown" id="topbar-notifications" data-notifications data-now="{{ now()->getTimestampMs() }}"
    hx-get="{{ route('notifications.menu') }}" hx-trigger="every 60s [!this.querySelector('.dropdown-menu.show')]" hx-swap="outerHTML">
    <a class="nav-link dropdown-toggle" data-bs-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false"
        title="Notifications" aria-label="Notifications{{ $unread > 0 ? ", {$unread} unread" : '' }}">
        <i class="fe-bell noti-icon"></i>
        @if ($unread > 0)
            <span class="badge bg-danger rounded-circle noti-icon-badge">{{ $unread > 99 ? '99+' : $unread }}</span>
        @endif
    </a>
    <div class="dropdown-menu dropdown-menu-end dropdown-lg">
        <div class="dropdown-item noti-title">
            <h5 class="m-0">
                @if ($unread > 0)
                    <form method="POST" action="{{ route('notifications.read-all') }}" class="float-end">
                        @csrf
                        <button type="submit" class="btn btn-link p-0 text-dark"><small>Mark all read</small></button>
                    </form>
                @endif
                Notifications
            </h5>
        </div>

        <div class="noti-scroll">
            @forelse ($latest as $notification)
                @php
                    $shown = App\Support\NotificationFeed::present($notification);
                @endphp
                <a href="{{ route('notifications.show', $notification) }}" @class(['dropdown-item', 'notify-item', 'active' => ! $notification->read_at])
                    data-at="{{ $notification->updated_at->getTimestampMs() }}" data-title="{{ $shown->title }}" data-text="{{ $shown->text }}"
                    @if (! $notification->read_at) data-unread @endif>
                    <div class="notify-icon bg-soft-primary text-primary">
                        <i class="{{ $shown->icon }}"></i>
                    </div>
                    <p class="notify-details">{{ $shown->title }}
                        <small class="text-muted">{{ $notification->updated_at->diffForHumans() }}</small>
                    </p>
                    <p class="text-muted mb-0 user-msg">
                        <small>{{ $shown->text }}</small>
                    </p>
                </a>
            @empty
                <div class="dropdown-item text-muted">Nothing yet.</div>
            @endforelse
        </div>

        <button type="button" class="dropdown-item text-center text-primary notify-item" data-notifications-enable hidden>
            Turn on browser notifications
        </button>
        <a href="{{ route('notifications.index') }}" class="dropdown-item text-center text-primary notify-item notify-all">
            All notifications <i class="fe-arrow-right"></i>
        </a>
    </div>
</li>
