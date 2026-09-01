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
