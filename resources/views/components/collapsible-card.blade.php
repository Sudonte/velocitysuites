@props(['title', 'icon' => null, 'id', 'defaultOpen' => true, 'bodyClass' => 'card-body'])

{{-- Same visual shell as x-card (identical .card/.card-header markup, so
     nothing looks different structurally) - just a clickable header wired
     to Bootstrap's native collapse component instead of a static heading.
     See public/js/app.js's initCollapsibleCards() for the localStorage
     persistence + chevron-rotation logic; Bootstrap's own collapse.js
     handles the actual show/hide animation. --}}
<div {{ $attributes->merge(['class' => 'card border-0 shadow-sm collapsible-card']) }}>
    <div class="card-header d-flex justify-content-between align-items-center collapsible-card-header"
         role="button" tabindex="0"
         data-bs-toggle="collapse" data-bs-target="#{{ $id }}"
         aria-expanded="{{ $defaultOpen ? 'true' : 'false' }}" aria-controls="{{ $id }}">
        <h5 class="mb-0">@if($icon)<i class="{{ $icon }}"></i> @endif{{ $title }}</h5>
        <i class="fas fa-chevron-down collapsible-card-chevron {{ $defaultOpen ? '' : 'rotated' }}"></i>
    </div>
    <div id="{{ $id }}" class="collapse {{ $defaultOpen ? 'show' : '' }}" data-collapse-persist-key="dash-collapse-{{ $id }}">
        <div class="{{ $bodyClass }}">
            {{ $slot }}
        </div>
    </div>
</div>
