{{-- The portfolio at a glance: the totals, its value over time and what is held. --}}
<x-layouts.app title="Dashboard">
    @include('dashboard._totals')
    @include('dashboard._chart')
    @include('dashboard._holdings')
</x-layouts.app>
