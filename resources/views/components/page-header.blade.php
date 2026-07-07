@props(['icon' => null, 'title', 'subtitle' => null])

<div class="page-header">
    <div>
        <h1 class="mb-0">
            @if($icon)<i class="{{ $icon }}"></i> @endif{{ $title }}
        </h1>
        @if($subtitle)
            <p class="page-subtitle">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="d-flex gap-2 flex-wrap">
            {{ $actions }}
        </div>
    @endisset
</div>
