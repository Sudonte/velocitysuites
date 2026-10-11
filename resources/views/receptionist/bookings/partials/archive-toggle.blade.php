{{-- Archive / Restore for a closed booking - archiving is reversible, nothing is deleted. --}}
@if($booking->hidden_at)
    <p class="text-muted small mb-2"><i class="fas fa-box-archive"></i> Archived {{ $booking->hidden_at->format('M d, Y h:i A') }}</p>
    <form action="{{ route('receptionist.bookings.restore', $booking) }}" method="POST" class="d-inline">
        @csrf
        @method('PUT')
        <button type="submit" class="btn btn-outline-success"><i class="fas fa-box-open"></i> Restore</button>
    </form>
@else
    <form action="{{ route('receptionist.bookings.archive', $booking) }}" method="POST" class="d-inline"
          data-confirm="It moves to the Archived list, where it can be restored." data-confirm-title="Archive this booking?" data-confirm-button="Archive" data-confirm-variant="secondary">
        @csrf
        @method('PUT')
        <button type="submit" class="btn btn-outline-secondary"><i class="fas fa-box-archive"></i> Archive</button>
    </form>
@endif
