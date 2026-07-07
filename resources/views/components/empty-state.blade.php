@props(['icon' => 'fas fa-inbox', 'message' => 'Nothing here yet.'])

<div class="empty-state">
    <i class="{{ $icon }}"></i>
    {{ $message }}
</div>
