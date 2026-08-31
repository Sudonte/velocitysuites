@props(['icon' => null, 'title', 'subtitle' => null, 'showClock' => false])

<div class="page-header">
    <div>
        <h1 class="mb-0">
            @if($icon)<i class="{{ $icon }}"></i> @endif{{ $title }}
        </h1>
        @if($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @if($showClock)
        <div class="page-header-clock text-end" data-page-header-clock>
            <div class="page-header-clock-time" data-clock-time>&nbsp;</div>
            <div class="page-header-clock-date" data-clock-date>&nbsp;</div>
        </div>
    @endif
    @isset($actions)
        <div class="d-flex gap-2 flex-wrap">
            {{ $actions }}
        </div>
    @endisset
</div>
