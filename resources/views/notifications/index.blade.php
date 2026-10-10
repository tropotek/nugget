{{-- Every notification the user has, the latest to change first. $notifications: a page of them. --}}
<x-layouts.app title="Notifications">
    <x-slot:actions>
        @if (auth()->user()->unreadNotifications()->exists())
            <form method="POST" action="{{ route('notifications.read-all') }}">
                @csrf
                <button type="submit" class="btn btn-light">Mark all read</button>
            </form>
        @endif
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <tbody>
                        @forelse ($notifications as $notification)
                            @php
                                $shown = App\Support\NotificationFeed::present($notification);
                            @endphp
                            <tr @class(['fw-semibold' => ! $notification->read_at])>
                                <td style="width: 1%"><i class="{{ $shown->icon }} fs-4 text-primary"></i></td>
                                <td>
                                    <a href="{{ route('notifications.show', $notification) }}">{{ $shown->title }}</a>
                                    @unless ($notification->read_at)
                                        <span class="badge bg-danger ms-1">New</span>
                                    @endunless
                                    <div class="text-muted fw-normal">{{ $shown->text }}</div>
                                </td>
                                <td class="text-end text-nowrap text-muted fw-normal"><x-date :value="$notification->updated_at" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td class="text-muted">No notifications.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($notifications->hasPages())
                <div class="mt-3">{{ $notifications->links() }}</div>
            @endif

            <p class="form-text mb-0 mt-3">
                A read notification is deleted after {{ config('nugget.notifications.keep_read_days') }} days. A balance that differs from the ledger stays until the two agree.
            </p>
        </div>
    </div>
</x-layouts.app>
