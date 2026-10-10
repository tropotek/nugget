{{-- $exchanges: the user's exchanges, each with assets_count. $drivers: App\Exchanges\ExchangeDrivers. --}}
@php($names = $drivers->names())

<x-layouts.app title="Exchanges">
    <x-slot:actions>
        <a href="{{ route('exchanges.create') }}" class="btn btn-primary"><i class="ri-add-line me-1"></i>Add exchange</a>
    </x-slot:actions>

    <div class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover table-centered mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Account</th>
                            <th>Exchange</th>
                            <th class="text-end">AUD balance</th>
                            <th class="text-end">Assets</th>
                            <th>Last sync</th>
                            <th>Last error</th>
                            <th></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($exchanges as $exchange)
                            <tr>
                                <td><a href="{{ route('exchanges.edit', $exchange) }}">{{ $exchange->label }}</a></td>
                                <td>
                                    @isset($names[$exchange->driver])
                                        {{ $names[$exchange->driver] }}
                                    @else
                                        <span class="text-danger" title="Nugget has no driver of this name">{{ $exchange->driver }}</span>
                                    @endisset
                                </td>
                                <td class="text-end">${{ $exchange->aud_balance->format() }}</td>
                                <td class="text-end">{{ $exchange->assets_count }}</td>
                                <td class="text-nowrap">
                                    @if ($exchange->last_synced_at)
                                        <x-date :value="$exchange->last_synced_at" />
                                    @else
                                        <span class="text-muted">Never</span>
                                    @endif
                                </td>
                                <td class="text-danger">{{ Str::limit((string) $exchange->last_error, 120) }}</td>
                                <td>
                                    @unless ($exchange->active)
                                        <span class="badge bg-secondary" title="An inactive exchange is not synced">Inactive</span>
                                    @endunless
                                </td>
                                <td class="text-end text-nowrap">
                                    @if ($drivers->can($exchange->driver, App\Exchanges\Contracts\ChecksConnection::class))
                                        <form method="POST" action="{{ route('exchanges.test', $exchange) }}" class="d-inline">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-light">Test connection</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('exchanges.edit', $exchange) }}" class="btn btn-sm btn-light">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-muted">No exchanges yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
