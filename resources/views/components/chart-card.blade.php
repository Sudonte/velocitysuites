{{--
    $href is echoed via {{ $href }} below, which escapes it exactly once -
    always pass it as :href="route(...)" at the call site, never
    href="{{ route(...) }}" (see components/stat-card.blade.php's identical
    note for why the latter double-escapes and silently corrupts any route
    with 2+ query params). No current usage here has a multi-param route,
    so nothing is broken today, but the same mistake would resurface the
    moment one does.
--}}
@props(['icon', 'title', 'canvasId', 'legend', 'href' => null])

@php
    $total = collect($legend)->sum('value');
@endphp

<div class="card border-0 shadow-sm chart-card h-100">
    <div class="card-header d-flex align-items-center gap-2">
        <span class="chart-card-icon"><i class="{{ $icon }}"></i></span>
        <h6 class="mb-0 fw-bold">{{ $title }}</h6>
    </div>
    <div class="card-body d-flex flex-column">
        <div class="chart-card-canvas-wrap">
            <canvas id="{{ $canvasId }}"></canvas>
        </div>
        <ul class="chart-card-legend list-unstyled mb-0 mt-3">
            @foreach($legend as $item)
                <li class="d-flex align-items-center justify-content-between">
                    <span class="d-flex align-items-center gap-2">
                        <span class="chart-legend-dot" style="background-color: {{ $item['color'] }};"></span>
                        {{ $item['label'] }}
                    </span>
                    <span class="text-end">
                        <strong>{{ $item['value'] }}</strong>
                        <span class="text-muted">({{ $total > 0 ? round($item['value'] / $total * 100, 1) : 0 }}%)</span>
                    </span>
                </li>
            @endforeach
        </ul>
        @if($href)
            <a href="{{ $href }}" class="chart-card-view-details mt-3">View Details <i class="fas fa-arrow-right"></i></a>
        @endif
    </div>
</div>
