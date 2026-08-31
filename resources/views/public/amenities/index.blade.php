@extends('layouts.public')

@section('title', 'Amenities - Velocity Suites')

@push('styles')
<style>
    .amenity-card {
        height: 100%;
        cursor: pointer;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .amenity-card:hover {
        transform: translateY(-4px);
    }

    .amenity-icon-badge {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background-color: rgba(193, 18, 31, 0.1);
        color: var(--primary-color);
        font-size: 1.4rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 1rem;
    }

    .amenity-view-details {
        font-size: 0.8rem;
        font-weight: 700;
        color: var(--primary-color);
    }
</style>
@endpush

@section('content')
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-spa"></i> Hotel Amenities</span>
                <h1 class="mt-3">Our <span class="text-brand">Amenities</span></h1>
                <p class="text-muted mx-auto" style="max-width: 560px;">
                    Everything you need for a comfortable, effortless stay - available to add to your reservation.
                </p>
            </div>

            @if($amenities->isEmpty())
                <div class="text-center py-5">
                    <i class="fas fa-spa fa-2x text-muted mb-3"></i>
                    <p class="text-muted mb-0">No amenities are currently available. Please check back later.</p>
                </div>
            @else
                <div class="row g-4">
                    @foreach($amenities as $amenity)
                        <div class="col-sm-6 col-lg-4">
                            <div class="card border-0 shadow-sm amenity-card"
                                 data-amenity-detail
                                 data-amenity-name="{{ $amenity->amenity_name }}"
                                 data-amenity-category="{{ $amenity->category }}"
                                 data-amenity-description="{{ $amenity->description }}"
                                 data-amenity-pricing="{{ $amenity->charge > 0 ? 'paid' : 'complimentary' }}"
                                 data-amenity-charge="{{ number_format($amenity->charge, 2) }}"
                                 data-amenity-stock="{{ $amenity->quantity }}"
                                 role="button" tabindex="0">
                                <div class="card-body p-4 text-center d-flex flex-column">
                                    <span class="amenity-icon-badge mx-auto"><i class="fas {{ $amenity->icon }}"></i></span>
                                    <h5 class="fw-bold">{{ $amenity->amenity_name }}</h5>
                                    @if($amenity->description)
                                        <p class="text-muted small mb-3">{{ Str::limit($amenity->description, 100) }}</p>
                                    @endif
                                    <p class="amenity-view-details mt-auto mb-0">
                                        View Details <i class="fas fa-arrow-right"></i>
                                    </p>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="text-center mt-5">
                <p class="text-muted mb-3">Amenities can be added when you book or during your stay.</p>
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity btn-lg">
                    <i class="fas fa-door-open"></i> Explore Rooms
                </a>
            </div>
        </div>
    </section>

    <x-amenity-detail-modal />
@endsection
