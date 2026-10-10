{{-- The page heading, with optional buttons or links on the right. --}}
@props(['title' => null, 'actions' => null])

@if ($title)
    <div class="row">
        <div class="col-12">
            <div class="page-title-box">
                <h4 class="page-title">{{ $title }}</h4>
                @if ($actions)
                    <div class="page-title-right">{{ $actions }}</div>
                @endif
            </div>
        </div>
    </div>
@endif
