@php
    $payload = [
        'title' => $a->title, 'content' => $a->content, 'status' => $a->displayStatus(), 'audience' => $a->audienceShort(),
        'images' => collect($a->images ?? [])->map(fn ($p) => \Illuminate\Support\Facades\Storage::disk('public')->url($p))->values(),
        'published' => $a->published_at?->format('F j, Y') ?? 'Not set', 'ends' => $a->ends_at?->format('F j, Y') ?? 'No end date',
        'notified' => $a->notified_at?->format('F j, Y g:i A') ?? 'Not yet sent', 'edited' => $a->last_edited_at?->format('F j, Y g:i A') ?? 'Not edited since publishing',
        'updated' => $a->updated_at->format('F j, Y g:i A'), 'edit' => route('admin.announcements.edit', $a),
    ];
@endphp
<div class="ann-actions">
    <button type="button" class="btn btn-outline-primary btn-sm" data-ann-view="{{ json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) }}" aria-label="View {{ $a->title }}"><i class="fas fa-eye"></i> View</button>
    <a href="{{ route('admin.announcements.edit', $a) }}" class="btn btn-outline-secondary btn-sm" aria-label="Edit {{ $a->title }}"><i class="fas fa-pen"></i> Edit</a>
    @if ($a->isArchived())
        <form action="{{ route('admin.announcements.unarchive', $a) }}" method="POST" class="d-inline">
            @csrf @method('PUT')
            <button type="submit" class="btn btn-outline-success btn-sm" aria-label="Restore {{ $a->title }}"><i class="fas fa-box-open"></i> Restore</button>
        </form>
    @else
        <form action="{{ route('admin.announcements.archive', $a) }}" method="POST" class="d-inline" data-confirm="It is switched off and moves to the Archived list, where it can be restored." data-confirm-title="Archive this announcement?" data-confirm-button="Archive" data-confirm-variant="secondary">
            @csrf @method('PUT')
            <button type="submit" class="btn btn-outline-secondary btn-sm" aria-label="Archive {{ $a->title }}"><i class="fas fa-box-archive"></i> Archive</button>
        </form>
    @endif
</div>
