{{-- The portfolio at a glance: the totals and its value over time. The holdings table is task 62. --}}
<x-layouts.app title="Dashboard">
    @include('dashboard._totals')
    @include('dashboard._chart')
</x-layouts.app>
