@if ($req->status === 'pending')
    <div class="d-inline-flex flex-wrap gap-2 pr-actions">
        <form action="{{ route('admin.users.password-requests.approve', $req) }}" method="POST"
              data-confirm="Temporary password velocitysuites123 is set for {{ $req->user->full_name }}. It works for 24 hours and must be changed at first login." data-confirm-title="Approve this request?" data-confirm-button="Approve" data-confirm-variant="success">
            @csrf
            @method('PUT')
            <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check"></i> Approve</button>
        </form>
        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $req->id }}"><i class="fas fa-times"></i> Reject</button>
    </div>
@else
    <span class="pr-muted">No action needed</span>
@endif
