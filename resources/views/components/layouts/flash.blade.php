{{--
    Messages flashed to the session by the previous request:
    ->with('success' | 'error' | 'warning' | 'info', '…'). Laravel's own 'status' is shown as a success.
--}}
@php
    $messages = array_filter([
        'success' => session('success') ?? session('status'),
        'danger' => session('error'),
        'warning' => session('warning'),
        'info' => session('info'),
    ]);
@endphp

@foreach ($messages as $style => $message)
    <div class="alert alert-{{ $style }} alert-dismissible fade show" role="alert">
        {{ $message }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endforeach
