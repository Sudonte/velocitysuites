<div class="modal-header modal-header-brand">
    <h5 class="modal-title"><i class="fas fa-spa"></i> Add Amenity Request</h5>
    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body" data-booking-id="{{ $booking->id }}">
    <div class="alert alert-danger d-none" id="amenityErrorAlert"></div>

    <p class="text-muted mb-3">
        <strong>Booking #{{ $booking->id }}</strong> &bull; {{ $booking->guest_display_name }} &bull;
        Room {{ $booking->room->room_number ?? 'N/A' }}
    </p>

    @if($amenities->isEmpty())
        <p class="text-muted mb-0">No Paid/Additional amenities are currently active.</p>
    @else
        <p class="text-muted small mb-2">Click an amenity to add it below, then set how many.</p>
        <div class="row g-3" id="amenityCardGrid">
            @foreach($amenities as $amenity)
                @php $inStock = (int) ($remainingStock[$amenity->id] ?? 0); @endphp
                <div class="col-sm-6 col-lg-4">
                    <div class="card h-100 amenity-pick-card {{ $inStock <= 0 ? 'amenity-pick-card-disabled' : '' }}"
                         data-amenity-id="{{ $amenity->id }}"
                         data-amenity-name="{{ $amenity->amenity_name }}"
                         data-amenity-charge="{{ $amenity->charge }}"
                         data-amenity-stock="{{ max(0, $inStock) }}"
                         role="button" tabindex="0">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                                <div class="d-flex align-items-start gap-2">
                                    <i class="fas {{ $amenity->icon }} text-brand fs-4"></i>
                                    <div>
                                        <p class="fw-bold mb-0">{{ $amenity->amenity_name }}</p>
                                        <small class="text-muted">{{ $amenity->category ?: 'Uncategorized' }}</small>
                                    </div>
                                </div>
                                <i class="fas fa-circle-check text-success amenity-pick-check d-none"></i>
                            </div>
                            <p class="mb-1 text-brand fw-bold">₱{{ number_format($amenity->charge, 2) }}</p>
                            <p class="mb-0 small {{ $inStock > 0 ? 'text-muted' : 'text-danger' }}">
                                <i class="fas fa-boxes-stacked"></i>
                                {{ $inStock > 0 ? "{$inStock} available" : 'Out of stock' }}
                            </p>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <hr>

        <h6 class="mb-2">Selected Amenities</h6>
        <p class="text-muted small mb-0" id="amenityEmptyMessage">Nothing selected yet.</p>
        <div id="amenitySelectedList"></div>
    @endif
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    @if($amenities->isNotEmpty())
        <button type="button" id="amenitySubmitBtn" class="btn btn-primary" disabled>
            <i class="fas fa-save"></i> Save Request(s)
        </button>
    @endif
</div>
