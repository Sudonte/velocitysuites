@props(['icon', 'label', 'value', 'color' => 'primary', 'href' => null])

@php
$colorMap = [
    'primary'   => ['bg' => 'rgba(193,18,31,0.12)',  'fg' => 'var(--primary-color)'],
    'success'   => ['bg' => 'rgba(40,167,69,0.12)',  'fg' => 'var(--success-color)'],
    'warning'   => ['bg' => 'rgba(255,193,7,0.18)',  'fg' => '#b8860b'],
    'danger'    => ['bg' => 'rgba(220,53,69,0.12)',  'fg' => 'var(--danger-color)'],
    'info'      => ['bg' => 'rgba(23,162,184,0.12)', 'fg' => 'var(--info-color)'],
    'gold'      => ['bg' => 'rgba(212,175,55,0.18)', 'fg' => 'var(--gold-color)'],
    'secondary' => ['bg' => 'rgba(108,117,125,0.12)','fg' => '#6c757d'],
];
$c = $colorMap[$color] ?? $colorMap['primary'];
@endphp

<div {{ $attributes->merge(['class' => 'stat-card position-relative']) }}>
    <div>
        <p class="stat-label">{{ $label }}</p>
        <p class="stat-value">{{ $value }}</p>
    </div>
    <div class="stat-icon" style="background-color: {{ $c['bg'] }}; color: {{ $c['fg'] }};">
        <i class="{{ $icon }}"></i>
    </div>
    @if($href)
        <a href="{{ $href }}" class="stretched-link" aria-label="{{ $label }}"></a>
    @endif
</div>
