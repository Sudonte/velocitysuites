@props(['icon', 'label', 'value', 'color' => 'primary', 'href' => null, 'change' => null])

@php
// Solid-fill cards read best with white text on the darker/more-saturated
// brand colors and dark text on the lighter ones (mirrors how a light
// gold/yellow card would look washed out with white text on top).
$colorMap = [
    'primary'   => ['bg' => 'var(--primary-color)', 'text' => '#fff', 'tint' => 'rgba(255,255,255,0.22)'],
    'success'   => ['bg' => 'var(--success-color)', 'text' => '#fff', 'tint' => 'rgba(255,255,255,0.22)'],
    'danger'    => ['bg' => 'var(--danger-color)',  'text' => '#fff', 'tint' => 'rgba(255,255,255,0.22)'],
    'info'      => ['bg' => 'var(--info-color)',    'text' => '#fff', 'tint' => 'rgba(255,255,255,0.22)'],
    'secondary' => ['bg' => '#6c757d',               'text' => '#fff', 'tint' => 'rgba(255,255,255,0.22)'],
    'warning'   => ['bg' => 'var(--warning-color)', 'text' => 'var(--text-dark)', 'tint' => 'rgba(0,0,0,0.1)'],
    'gold'      => ['bg' => 'var(--gold-color)',    'text' => 'var(--text-dark)', 'tint' => 'rgba(0,0,0,0.1)'],
];
$c = $colorMap[$color] ?? $colorMap['primary'];
@endphp

<div {{ $attributes->merge(['class' => 'stat-card-fill position-relative' . ($href ? ' has-link' : '')]) }}
     style="background-color: {{ $c['bg'] }}; color: {{ $c['text'] }};">
    <div class="d-flex align-items-start justify-content-between">
        <p class="stat-fill-label mb-0">{{ $label }}</p>
        <span class="stat-fill-icon" style="background-color: {{ $c['tint'] }};">
            <i class="{{ $icon }}"></i>
        </span>
    </div>
    <p class="stat-fill-value mb-0">{{ $value }}</p>
    @if($change !== null)
        <p class="stat-fill-change mb-0">
            <i class="fas fa-{{ $change > 0 ? 'arrow-up' : ($change < 0 ? 'arrow-down' : 'minus') }}"></i>
            {{ $change > 0 ? '+' : '' }}{{ $change }}% from last period
        </p>
    @endif
    @if($href)
        <div class="stat-fill-footer">
            <span>View Details <i class="fas fa-arrow-right"></i></span>
        </div>
        <a href="{{ $href }}" class="stretched-link" aria-label="{{ $label }}"></a>
    @endif
</div>
