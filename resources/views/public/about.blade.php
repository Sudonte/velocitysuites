@extends('layouts.public')

@section('title', 'About Us - Velocity Suites')

@push('styles')
<style>
    .about-hero {
        background: linear-gradient(180deg, rgba(193, 18, 31, 0.06) 0%, rgba(193, 18, 31, 0) 100%);
    }

    .about-page-image-slider {
        position: relative;
        overflow: hidden;
        min-height: 320px;
    }

    .about-page-slide {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 0;
        animation: aboutPageSlideFade 10s ease-in-out infinite;
    }

    .about-page-slide-1 { animation-delay: 0s; }
    .about-page-slide-2 { animation-delay: -5s; }

    @keyframes aboutPageSlideFade {
        0%, 45% { opacity: 1; }
        50%, 95% { opacity: 0; }
        100% { opacity: 1; }
    }

    @media (prefers-reduced-motion: reduce) {
        .about-page-slide { animation: none; }
        .about-page-slide-1 { opacity: 1; }
        .about-page-slide-2 { opacity: 0; }
    }

    .about-gallery-img {
        width: 100%;
        height: 220px;
        object-fit: cover;
        border-radius: var(--radius-card, 1rem);
    }

    /* Identity chips under the "Who We Are" copy - qualitative facts
       (location, ownership), not numeric statistics, so they stay after
       the Key Stats block was removed per an explicit request to drop
       Guest Rooms/Staff/Daily/Monthly/Breakfast-time figures. */
    .about-fact-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        background-color: rgba(193, 18, 31, 0.06);
        color: var(--text-dark);
        font-weight: 600;
        font-size: 0.85rem;
        padding: 0.55rem 1rem;
        border-radius: var(--radius-pill);
    }

    .about-fact-chip i {
        color: var(--primary-color);
    }

    /* Our Rooms - a small circular type icon (imagery in lieu of a real
       per-room photo) plus the capacity pill and a top accent bar
       distinguish these from the plain Services & Facilities icon cards. */
    .room-info-card {
        background: #fff;
        border-radius: var(--radius-card, 1rem);
        box-shadow: var(--shadow-sm);
        padding: 1.75rem;
        height: 100%;
        border-top: 4px solid var(--primary-color);
        transition: transform 0.25s ease, box-shadow 0.25s ease;
    }

    .room-info-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }

    .room-info-icon {
        width: 48px;
        height: 48px;
        border-radius: 50%;
        background-color: rgba(193, 18, 31, 0.1);
        color: var(--primary-color);
        font-size: 1.15rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 0.9rem;
    }

    .room-info-capacity {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        background-color: rgba(193, 18, 31, 0.08);
        color: var(--primary-color);
        font-weight: 700;
        font-size: 0.78rem;
        padding: 0.3rem 0.7rem;
        border-radius: var(--radius-pill);
        margin-bottom: 0.75rem;
        margin-left: 0.5rem;
    }

    /* Guest Experience - promoted into its own card (previously a loose
       icon+text row) so it carries the same visual weight as the other
       card-based sections on the page. */
    .experience-banner {
        background: #fff;
        border-radius: var(--radius-card, 1rem);
        box-shadow: var(--shadow-sm);
        padding: 2.25rem;
    }

    /* Our Team - simple centered number+label trio, no cards needed since
       it's just three related figures that already sum to nine staff. */
    .team-stat-item {
        text-align: center;
    }

    .team-stat-value {
        font-size: 2rem;
        font-weight: 800;
        color: var(--primary-color);
        line-height: 1;
    }

    .team-stat-label {
        font-size: 0.85rem;
        color: var(--text-light);
        margin-top: 0.35rem;
    }

    /* Peak Seasons and Local Festivals */
    .festival-card {
        background: #fff;
        border-radius: var(--radius-card, 1rem);
        box-shadow: var(--shadow-sm);
        padding: 2rem;
        height: 100%;
    }

    .festival-date-badge {
        display: inline-block;
        background-color: var(--primary-color);
        color: #fff;
        font-weight: 700;
        font-size: 0.75rem;
        padding: 0.3rem 0.75rem;
        border-radius: var(--radius-pill);
        margin-bottom: 0.85rem;
    }

    /* Closing CTA - a bold brand-gradient card instead of plain text on a
       light band, for a stronger hospitality-style closing note. */
    .about-cta-card {
        background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
        border-radius: var(--radius-card, 1rem);
        box-shadow: var(--shadow-md);
        padding: 3rem 2rem;
        color: #fff;
    }

    .about-cta-card .btn-outline-light {
        border-width: 2px;
        font-weight: 600;
    }
</style>
@endpush

@section('content')
    <!-- About Hero -->
    <section class="about-hero py-5">
        <div class="container text-center">
            <span class="section-badge mb-3"><i class="fas fa-hotel"></i> About Velocity Suites</span>
            <h1 class="mt-3">Your Comfort <span class="text-brand">is Our Service</span></h1>
            <p class="text-muted mx-auto" style="max-width: 640px;">
                Velocity Suites is a modern hotel in Surallah, South Cotabato, built around one simple idea:
                every guest deserves a comfortable, convenient, and memorable stay.
            </p>
        </div>
    </section>

    <!-- About Velocity Suites -->
    <section class="py-5">
        <div class="container">
            <div class="row align-items-stretch g-5">
                <div class="col-lg-5">
                    <div class="about-page-image-slider rounded shadow w-100 h-100">
                        <img src="{{ asset('images/about-1.jpg') }}" alt="Velocity Suites guests" class="about-page-slide about-page-slide-1">
                        <img src="{{ asset('images/about-2.jpg') }}" alt="Velocity Suites guests" class="about-page-slide about-page-slide-2">
                    </div>
                </div>
                <div class="col-lg-7 d-flex flex-column justify-content-center">
                    <span class="section-badge mb-3 align-self-start"><i class="fas fa-info-circle"></i> About Velocity Suites</span>
                    <h2 class="mb-4">Who <span class="text-brand">We Are</span></h2>
                    <p class="mb-3 text-muted">
                        Velocity Suites is an accommodation establishment located in Surallah, South Cotabato,
                        Philippines, owned and managed by <strong>Ms. Venise Nhicole Pendon</strong>. We primarily
                        serve local travelers and visiting guests, offering a convenient, relaxing, and memorable
                        stay through quality accommodations and friendly service.
                    </p>
                    <p class="mb-4 text-muted">
                        With thoughtfully prepared facilities and guest-focused services, we strive to create a
                        pleasant environment where every guest can feel at home - whether visiting Surallah for
                        business, leisure, or any other purpose.
                    </p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="about-fact-chip"><i class="fas fa-location-dot"></i> Surallah, South Cotabato</span>
                        <span class="about-fact-chip"><i class="fas fa-user-tie"></i> Owned &amp; Managed by Ms. Venise Nhicole Pendon</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Our Rooms -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-bed"></i> Our Rooms</span>
                <h2 class="mt-3">Room Types <span class="text-brand">for Every Guest</span></h2>
                <p class="text-muted mx-auto" style="max-width: 620px;">
                    Twenty-eight rooms across five types, each designed to meet different guest needs -
                    regardless of room type, every accommodation aims to provide a comfortable and satisfying
                    experience.
                </p>
            </div>
            <div class="row g-4">
                <div class="col-sm-6 col-lg-4">
                    <div class="room-info-card">
                        <span class="room-info-icon"><i class="fas fa-bed"></i></span>
                        <h5 class="fw-bold mb-2">Standard Room <span class="room-info-capacity"><i class="fas fa-user"></i> 1 Guest</span></h5>
                        <p class="text-muted mb-0 small">A quiet and comfortable space suited for the solo traveler.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="room-info-card">
                        <span class="room-info-icon"><i class="fas fa-gem"></i></span>
                        <h5 class="fw-bold mb-2">Deluxe Room <span class="room-info-capacity"><i class="fas fa-user-friends"></i> 2 Guests</span></h5>
                        <p class="text-muted mb-0 small">A more relaxing environment, well-suited for couples.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="room-info-card">
                        <span class="room-info-icon"><i class="fas fa-couch"></i></span>
                        <h5 class="fw-bold mb-2">Superior Room <span class="room-info-capacity"><i class="fas fa-user-friends"></i> 2 Guests</span></h5>
                        <p class="text-muted mb-0 small">Two separate beds, ideal for two friends or companions traveling together.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="room-info-card">
                        <span class="room-info-icon"><i class="fas fa-people-roof"></i></span>
                        <h5 class="fw-bold mb-2">Family Suite Room <span class="room-info-capacity"><i class="fas fa-users"></i> 4 Guests</span></h5>
                        <p class="text-muted mb-0 small">A spacious setting designed for group stays and family trips.</p>
                    </div>
                </div>
                <div class="col-sm-6 col-lg-4">
                    <div class="room-info-card">
                        <span class="room-info-icon"><i class="fas fa-briefcase"></i></span>
                        <h5 class="fw-bold mb-2">Executive Suite Room <span class="room-info-capacity"><i class="fas fa-user-friends"></i> 3 Guests</span></h5>
                        <p class="text-muted mb-0 small">Extra space and comfort for guests who want room to relax or work.</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Guest Experience -->
    <section class="py-5">
        <div class="container">
            <div class="experience-banner">
                <div class="row align-items-center g-4">
                    <div class="col-lg-2 text-center">
                        <span class="highlight-icon-badge"><i class="fas fa-mug-hot"></i></span>
                    </div>
                    <div class="col-lg-10">
                        <span class="section-badge mb-3"><i class="fas fa-concierge-bell"></i> Guest Experience</span>
                        <h2 class="mt-3 mb-3">Starting Every Day <span class="text-brand">Right</span></h2>
                        <p class="text-muted mb-0">
                            Velocity Suites serves complimentary breakfast daily for its guests. Regardless of
                            room type, all our accommodations aim to provide a comfortable and satisfying
                            experience for every guest who stays with us.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Our Team -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-people-group"></i> Our Team</span>
                <h2 class="mt-3">The People <span class="text-brand">Behind Your Stay</span></h2>
                <p class="text-muted mx-auto" style="max-width: 560px;">
                    Velocity Suites is supported by a dedicated team who keep every stay running smoothly.
                </p>
            </div>
            <div class="row g-4 row-cols-1 row-cols-sm-3 justify-content-center text-center">
                <div class="col">
                    <div class="team-stat-item">
                        <div class="team-stat-value">2</div>
                        <div class="team-stat-label">Front Desk Personnel</div>
                    </div>
                </div>
                <div class="col">
                    <div class="team-stat-item">
                        <div class="team-stat-value">6</div>
                        <div class="team-stat-label">Housekeeping Staff</div>
                    </div>
                </div>
                <div class="col">
                    <div class="team-stat-item">
                        <div class="team-stat-value">1</div>
                        <div class="team-stat-label">Management Personnel</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Services & Facilities -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-concierge-bell"></i> What We Offer</span>
                <h2 class="mt-3">Services <span class="text-brand">&amp; Facilities</span></h2>
            </div>
            <div class="row g-4 text-center">
                <div class="col-md-3 col-sm-6">
                    <span class="highlight-icon-badge"><i class="fas fa-door-open"></i></span>
                    <h5 class="fw-bold">Well-Appointed Rooms</h5>
                    <p class="text-muted mb-0 small">Clean, comfortable rooms for every kind of stay.</p>
                </div>
                <div class="col-md-3 col-sm-6">
                    <span class="highlight-icon-badge"><i class="fas fa-concierge-bell"></i></span>
                    <h5 class="fw-bold">Attentive Service</h5>
                    <p class="text-muted mb-0 small">A friendly front desk ready to assist, 24/7.</p>
                </div>
                <div class="col-md-3 col-sm-6">
                    <span class="highlight-icon-badge"><i class="fas fa-map-location-dot"></i></span>
                    <h5 class="fw-bold">Prime Location</h5>
                    <p class="text-muted mb-0 small">Along Allah Valley Drive, easy to find and reach.</p>
                </div>
                <div class="col-md-3 col-sm-6">
                    <span class="highlight-icon-badge"><i class="fas fa-calendar-check"></i></span>
                    <h5 class="fw-bold">Event Booking</h5>
                    <p class="text-muted mb-0 small">Day and night slots available for gatherings.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- History and Operations -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-scroll"></i> Our Journey</span>
                <h2 class="mt-3">History <span class="text-brand">and Operations</span></h2>
            </div>
            <div class="row justify-content-center">
                <div class="col-lg-9">
                    <p class="text-muted">
                        Despite having a steady number of clients, Velocity Suites previously relied mainly on
                        manual methods to manage reservations, bookings, room allocation, and payment transactions.
                        Most operational activities were handled by a limited number of staff, which increased
                        their workload and affected service efficiency - especially during periods of high
                        customer demand.
                    </p>
                    <p class="text-muted mb-0">
                        Servicing guests daily depending on peak and off-peak seasons, the establishment
                        recognized that its continued growth called for a more efficient way of managing
                        day-to-day operations.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Peak Seasons and Local Festivals -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-star"></i> Peak Seasons</span>
                <h2 class="mt-3">Local <span class="text-brand">Festivals</span></h2>
                <p class="text-muted mx-auto" style="max-width: 640px;">
                    During peak seasons, Velocity Suites experiences a high number of reservations, with many
                    guests booking at least one month in advance.
                </p>
            </div>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="festival-card">
                        <span class="festival-date-badge"><i class="fas fa-calendar-days"></i> June 20-24</span>
                        <h5 class="fw-bold">Surbétube Festival</h5>
                        <p class="text-muted mb-0">
                            Held every June, the Surbétube Festival showcases local culture, traditions, and
                            community activities, drawing more visitors to Surallah during this period.
                        </p>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="festival-card">
                        <span class="festival-date-badge"><i class="fas fa-calendar-days"></i> December 1-30</span>
                        <h5 class="fw-bold">Surallah Christmas Festival</h5>
                        <p class="text-muted mb-0">
                            Held throughout December, the Surallah Christmas Festival features festive decorations
                            such as belen, Christmas trees, and parol, encouraging more tourists to visit and stay
                            in the area.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Why We Improved Our System -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row justify-content-center text-center">
                <div class="col-lg-9">
                    <span class="section-badge mb-3"><i class="fas fa-lightbulb"></i> Why We Improved Our System</span>
                    <h2 class="mt-3 mb-4">Built <span class="text-brand">for Velocity Suites</span></h2>
                    <p class="text-muted">
                        The management of Velocity Suites recognized the importance of improving operational
                        efficiency and customer service through the use of technology. However, due to the absence
                        of a centralized booking and reservation system, the establishment continued to depend on
                        manual record-keeping practices - affecting the accuracy of booking records, room
                        availability tracking, payment monitoring, and customer information management.
                    </p>
                    <p class="text-muted mb-0">
                        These challenges ultimately motivated the development of this web-based booking and
                        reservation management system, built specifically around the operational needs of
                        Velocity Suites.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <!-- Gallery -->
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-images"></i> Gallery</span>
                <h2 class="mt-3">A Glimpse <span class="text-brand">of Velocity Suites</span></h2>
            </div>
            <div class="row g-4">
                <div class="col-md-4">
                    <img src="{{ asset('images/hero-building.jpg') }}" alt="Velocity Suites building" class="about-gallery-img shadow-sm">
                </div>
                <div class="col-md-4">
                    <img src="{{ asset('images/about-1.jpg') }}" alt="Velocity Suites" class="about-gallery-img shadow-sm">
                </div>
                <div class="col-md-4">
                    <img src="{{ asset('images/about-2.jpg') }}" alt="Velocity Suites" class="about-gallery-img shadow-sm">
                </div>
            </div>
        </div>
    </section>

    <!-- CTA -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="about-cta-card text-center">
                <h2 class="mb-3 text-white">Ready to Experience <span class="text-gold">Velocity Suites?</span></h2>
                <p class="mb-4" style="color: rgba(255,255,255,0.85);">Explore our rooms and find the perfect stay for your next visit to Surallah.</p>
                <a href="{{ route('public.rooms.index') }}" class="btn btn-light btn-lg me-2 fw-semibold">Explore Rooms</a>
                <a href="{{ route('public.contact') }}" class="btn btn-outline-light btn-lg">Contact Us</a>
            </div>
        </div>
    </section>
@endsection
