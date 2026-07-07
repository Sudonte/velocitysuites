@props(['title' => null, 'icon' => null, 'variant' => 'primary', 'bodyClass' => ''])

<div {{ $attributes->merge(['class' => 'card border-0 shadow-sm']) }}>
    @if($title)
        <div class="card-header {{ $variant !== 'primary' ? 'card-header-'.$variant : '' }} d-flex justify-content-between align-items-center">
            <h5 class="mb-0">@if($icon)<i class="{{ $icon }}"></i> @endif{{ $title }}</h5>
            @isset($actions)
                <div>{{ $actions }}</div>
            @endisset
        </div>
    @endif
    <div class="{{ $bodyClass }}">
        {{ $slot }}
    </div>
    @isset($footer)
        <div class="card-footer">{{ $footer }}</div>
    @endisset
</div>
