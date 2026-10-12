{{--
    Walk-in Check-in: paid amenities the guest wants from the start. Each one
    picked becomes an approved request on the booking, billed and paid in full
    with the rooms at check-in (CheckInController::storeWalkIn()).
--}}
@if($walkInAmenities->isNotEmpty())
    <h6 class="form-section-heading">Amenities <small class="text-muted fw-normal">(optional - paid at check-in)</small></h6>
    <div class="border rounded p-2 mb-3">
        @error('amenities')<div class="alert alert-danger py-1 px-2 small mb-2">{{ $message }}</div>@enderror
        @foreach($walkInAmenities as $i => $amenity)
            @php $oldQty = (int) data_get(collect(old('amenities', []))->firstWhere('amenity_id', (string) $amenity->id), 'quantity', 0); @endphp
            <div class="d-flex align-items-center justify-content-between gap-2 py-1 {{ $loop->last ? '' : 'border-bottom' }}">
                <span>{{ $amenity->amenity_name }} <small class="text-muted">&middot; ₱{{ number_format((float) $amenity->charge, 2) }} each &middot; {{ $amenity->remaining }} left</small></span>
                <input type="hidden" name="amenities[{{ $i }}][amenity_id]" value="{{ $amenity->id }}">
                <input type="number" name="amenities[{{ $i }}][quantity]" class="form-control form-control-sm" style="width: 90px"
                       min="0" max="{{ $amenity->remaining }}" value="{{ $oldQty }}" data-charge="{{ (float) $amenity->charge }}" data-name="{{ $amenity->amenity_name }}" {{ $amenity->remaining < 1 ? 'disabled' : '' }} aria-label="Quantity of {{ $amenity->amenity_name }}">
            </div>
        @endforeach
    </div>
@endif
