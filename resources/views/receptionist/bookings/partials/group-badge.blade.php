{{-- "Group of 2 stays": this booking was booked together with others (one payment, one cancel-all). Verify the whole group in one action,
     or each stay on its own with its own Verify button. Expects $booking and $groupSizes (group_id => number of stays). --}}
@if($booking->group_id && ($groupSizes[$booking->group_id] ?? 0) > 1)
    <div class="small mb-1">
        <span class="badge bg-info text-dark"><i class="fas fa-layer-group"></i> Group of {{ $groupSizes[$booking->group_id] }} stays</span>
        @if($tab === 'pending')
            <form action="{{ route('receptionist.booking-groups.verify', $booking->group_id) }}" method="POST" class="d-inline" data-confirm="Every stay of this group whose payment is already verified will be marked as verified." data-confirm-title="Verify the whole group?" data-confirm-button="Verify group" data-confirm-variant="success">
                @csrf
                @method('PUT')
                <button type="submit" class="btn btn-sm btn-outline-success py-0">Verify the whole group</button>
            </form>
        @endif
    </div>
@endif
