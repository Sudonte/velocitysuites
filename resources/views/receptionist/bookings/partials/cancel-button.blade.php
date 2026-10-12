{{-- Cancel a confirmed booking straight from the Bookings list (opens #cancelBookingModal in index.blade.php). --}}
@if($booking->booking_status === \App\Models\Booking::STATUS_ACTIVE)
    @php $paid = $booking->paidTotal(); @endphp
    <button type="button" class="btn btn-outline-danger btn-sm btn-icon btn-cancel-booking {{ $mobile ? 'flex-fill' : '' }}" title="Cancel booking" aria-label="Cancel booking"
            data-url="{{ route('receptionist.bookings.cancel', $booking) }}" data-ref="Booking #{{ $booking->id }}" data-guest="{{ $booking->guest_display_name }}"
            data-paid="{{ $paid > 0.004 ? '₱' . number_format($paid, 2) : '' }}">
        <i class="fas fa-ban"></i></button>
@endif
