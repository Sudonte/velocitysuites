@props(['icon' => 'fas fa-inbox', 'message' => 'Nothing here yet.'])

<div class="empty-state">
    <i class="{{ $icon }}"></i>
    {{ $slot->isEmpty() ? $message : $slot }}
</div>
