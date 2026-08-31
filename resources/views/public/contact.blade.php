@extends('layouts.public')

@section('title', 'Contact Us - Velocity Suites')

@push('styles')
<style>
    /* Contact Us - map + info strip live inside one .card so they read as a
       single connected component (shared border-radius/shadow, no gap
       between them) rather than two stacked boxes. The card/item/icon/
       label/value base styling lives in layouts/public.blade.php's shared
       style block (the Home page's own Contact section reuses it too) -
       only this page's own 6-item/3-column divider rules stay local here. */

    /* sm-lg (2 columns, 3 rows of 2): vertical divider between the pair,
       plus drop the bottom border on the last row (items 5 and 6) so the
       grid doesn't end in a trailing line. */
    @media (min-width: 576px) and (max-width: 991.98px) {
        .contact-info-item {
            border-left: 1px solid rgba(0, 0, 0, 0.06);
        }

        .contact-info-item:nth-child(odd) {
            border-left: none;
        }

        .contact-info-item:nth-child(5),
        .contact-info-item:nth-child(6) {
            border-bottom: none;
        }
    }

    /* lg+ (3 columns, exactly 2 rows of 3): a true grid of dividers - left
       border between columns (skipped on the first column of each row),
       top border between the two rows (skipped on the first row). */
    @media (min-width: 992px) {
        .contact-info-item {
            border-bottom: none;
            border-left: 1px solid rgba(0, 0, 0, 0.06);
            border-top: 1px solid rgba(0, 0, 0, 0.06);
        }

        .contact-info-item:nth-child(3n+1) {
            border-left: none;
        }

        .contact-info-item:nth-child(-n+3) {
            border-top: none;
        }
    }

</style>
@endpush

@section('content')
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-envelope-open-text"></i> Get in Touch</span>
                <h1 class="mt-3">Contact <span class="text-brand">Us</span></h1>
                <p class="text-muted">Velocity Suites Hotel - we'd love to hear from you.</p>
            </div>

            <!-- Map preview + contact information as one unified card - the
                 map sits on top, the info strip directly beneath it inside
                 the same card, so the two read as a single connected
                 component rather than two separate boxes. -->
            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card border-0 shadow contact-card overflow-hidden">
                        <div class="ratio ratio-16x9">
                            @if(config('services.google_maps.key'))
                                <iframe
                                    src="https://www.google.com/maps/embed/v1/place?key={{ config('services.google_maps.key') }}&q=6.368977,124.7428036&zoom=16"
                                    title="Velocity Suites location map"
                                    style="border:0;"
                                    allowfullscreen=""
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"></iframe>
                            @else
                                {{-- Keyless fallback - stays functional even if GOOGLE_MAPS_API_KEY isn't configured. --}}
                                <iframe
                                    src="https://maps.google.com/maps?q=6.368977,124.7428036&z=16&output=embed"
                                    title="Velocity Suites location map"
                                    style="border:0;"
                                    allowfullscreen=""
                                    loading="lazy"
                                    referrerpolicy="no-referrer-when-downgrade"></iframe>
                            @endif
                        </div>

                        <div class="text-center py-3 contact-directions-bar">
                            <a href="https://www.google.com/maps/search/?api=1&query=6.368977,124.7428036"
                               target="_blank" rel="noopener" class="btn btn-outline-danger btn-sm">
                                <i class="fas fa-directions"></i> Get Directions
                            </a>
                        </div>

                        <!-- Contact information - exactly two rows of three
                             on desktop/laptop (lg+): Address/Contact
                             Number/Email, then Operating Hours/Event
                             Booking/Website. Stacks to 2-per-row on tablet
                             and 1-per-row on mobile instead of forcing the
                             3-wide layout where there isn't room for it. -->
                        <div class="contact-info-strip">
                            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-0">
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-map-marker-alt"></i></span>
                                    <h6 class="contact-info-label">Address</h6>
                                    <p class="contact-info-value">Allah Valley Drive National Highway, Surallah, South Cotabato, 9512</p>
                                </div>
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-phone"></i></span>
                                    <h6 class="contact-info-label">Contact Number</h6>
                                    <p class="contact-info-value"><a href="tel:09551392737">0955 139 2737</a></p>
                                </div>
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-envelope"></i></span>
                                    <h6 class="contact-info-label">Email</h6>
                                    <p class="contact-info-value"><a href="mailto:velocitysuites@gmail.com">velocitysuites@gmail.com</a></p>
                                </div>
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-clock"></i></span>
                                    <h6 class="contact-info-label">Operating Hours</h6>
                                    <p class="contact-info-value">Front Desk: 24 / 7<br>Check-In: From 2:00 PM<br>Check-Out: Until 12:00 PM</p>
                                </div>
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-calendar-check"></i></span>
                                    <h6 class="contact-info-label">Event Booking</h6>
                                    <p class="contact-info-value">Day Slot: 8:00 AM - 6:00 PM<br>Night Slot: 7:00 PM - 5:00 AM</p>
                                </div>
                                <div class="col contact-info-item">
                                    <span class="contact-info-icon"><i class="fas fa-globe"></i></span>
                                    <h6 class="contact-info-label">Website</h6>
                                    <p class="contact-info-value"><a href="https://www.velocitysuites.com">www.velocitysuites.com</a></p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="text-center mt-5">
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity btn-lg">
                    <i class="fas fa-door-open"></i> Explore Our Rooms
                </a>
            </div>
        </div>
    </section>
@endsection
