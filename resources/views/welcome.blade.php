@extends('layouts.public')

@section('title', 'Velocity Suites - Luxury Hotel Booking')

@push('styles')
<style>
    /* Landing-page-only hero: one full-bleed-photo composition, but with a
       deliberately DIFFERENT alignment scheme by breakpoint (not the fully
       unified one used elsewhere on this page) - desktop/laptop (>=992px)
       is left-aligned, no logo (matches the navbar's own branding instead
       of repeating it); mobile/tablet (<992px) is centered with the logo
       shown above the eyebrow. Base rules below are the desktop version;
       the `max-width: 991.98px` block further down flips every centering
       property to its mobile equivalent and reveals the logo - kept as
       overrides on the same classes rather than truly separate markup, so
       there's still only one DOM structure to maintain. The hero is a
       deliberate "first page" - min-height: 100vh (100dvh where supported,
       so mobile browser chrome showing/hiding doesn't leave a sliver of
       the next section peeking in) so the highlight strip below it never
       bleeds into the initial view on tall screens. .hero-media stays
       absolutely positioned so it's out of flow and doesn't participate
       in the alignment/centering rules either version uses.
       The two height-tier media queries below (matching real available
       viewport height, not assumed device/zoom numbers) keep the content
       itself from ever needing more room than a short 100vh can offer -
       they're what makes this safe across zoom levels and devices, since
       min-height alone doesn't prevent content overflow on a short screen. */
    .hero-section {
        position: relative;
        background-color: #0a0a0a;
        overflow: hidden;
        min-height: 100vh;
        min-height: 100dvh;
        display: flex;
        align-items: center;
        justify-content: flex-start;
        text-align: left;
    }

    .hero-media {
        position: absolute;
        inset: 0;
        z-index: 0;
    }

    .hero-media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
    }

    /* Now that the hero content is centered (not left-aligned), a
       directional dark-to-light gradient would leave the right half of
       the centered text sitting on the brighter, low-contrast part of the
       photo. A fairly uniform dark tint keeps every line readable
       regardless of where it falls horizontally, with a touch more depth
       at the top/bottom edges. */
    .hero-media::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(8, 8, 8, 0.72) 0%, rgba(8, 8, 8, 0.58) 45%, rgba(8, 8, 8, 0.75) 100%);
        pointer-events: none;
    }

    .hero-content {
        position: relative;
        z-index: 2;
        max-width: 680px;
        margin: 0;
        padding: 3rem clamp(1.5rem, 6vw, 5rem) 3.5rem;
        color: #fff;
    }

    /* Desktop: hidden - the navbar already carries the logo, and the
       reference direction for the left-aligned layout doesn't repeat it.
       Shown only on mobile/tablet via the max-width: 991.98px block below. */
    .hero-logo-wrap {
        display: none;
        justify-content: center;
        margin-bottom: 1.25rem;
    }

    .hero-logo-badge {
        width: 76px;
        height: 66px;
        box-shadow: var(--shadow-md);
    }

    .hero-eyebrow {
        display: block;
        font-size: 0.9rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: rgba(255, 255, 255, 0.85);
        margin-bottom: 0.5rem;
    }

    .hero-eyebrow-underline {
        display: block;
        width: 40px;
        height: 3px;
        margin-left: 0;
        margin-right: auto;
        background-color: var(--primary-color);
        border-radius: var(--radius-pill);
        margin-bottom: 1.25rem;
    }

    .hero-heading {
        font-size: clamp(3.25rem, 6vw, 5.5rem);
        font-weight: 800;
        line-height: 1.1;
        margin-bottom: 1.25rem;
        color: #fff;
    }

    /* Moved closer to the tagline it decorates (smaller margin-bottom
       below it than the heading's margin-above), rather than sitting
       exactly midway between the heading and the tagline - reads as an
       accent belonging to "Your Comfort is our Service" instead of a
       neutral divider between two unrelated blocks. */
    .hero-divider {
        width: 56px;
        height: 4px;
        margin-left: 0;
        margin-right: auto;
        background-color: var(--primary-color);
        border-radius: var(--radius-pill);
        margin-bottom: 0.4rem;
    }

    .hero-tagline {
        font-weight: 700;
        font-size: clamp(1.15rem, 1.9vw, 1.55rem);
        margin-bottom: 0.5rem;
        color: #fff;
    }

    .hero-desc {
        color: rgba(255, 255, 255, 0.82);
        font-size: 1.1rem;
        line-height: 1.65;
        max-width: 560px;
        margin-left: 0;
        margin-right: auto;
        margin-bottom: 1.5rem;
    }

    .hero-actions {
        display: flex;
        flex-wrap: wrap;
        justify-content: flex-start;
        gap: 1rem;
    }

    .btn-hero {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.9rem 2.1rem;
        font-size: 1.1rem;
        font-weight: 600;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }

    .btn-hero:hover {
        transform: translateY(-2px);
    }

    .btn-hero.btn-velocity:hover {
        box-shadow: 0 8px 20px rgba(193, 18, 31, 0.35);
    }

    .btn-hero:focus-visible {
        outline: 2px solid #fff;
        outline-offset: 3px;
    }

    .btn-hero-outline {
        display: inline-flex;
        align-items: center;
        gap: 0.6rem;
        padding: 0.9rem 2.1rem;
        font-size: 1.1rem;
        font-weight: 600;
        border-radius: var(--radius-btn);
        background-color: transparent;
        color: #fff;
        border: 2px solid rgba(255, 255, 255, 0.55);
        transition: all 0.2s ease;
    }

    .btn-hero-outline:hover,
    .btn-hero-outline:focus-visible {
        background-color: rgba(255, 255, 255, 0.12);
        border-color: #fff;
        color: #fff;
        transform: translateY(-2px);
    }

    .btn-hero:focus-visible,
    .btn-hero-outline:focus-visible {
        outline: 2px solid #fff;
        outline-offset: 3px;
    }

    /* Scroll-down affordance - desktop-only bonus flourish (hidden whenever
       the compact height tier kicks in below), not load-bearing content. */
    .hero-scroll-cue {
        display: none;
        align-items: center;
        gap: 0.6rem;
        margin-top: 1.75rem;
        color: rgba(255, 255, 255, 0.65);
        font-size: 0.8rem;
        font-weight: 600;
        letter-spacing: 0.03em;
    }

    .hero-scroll-cue i {
        animation: heroScrollBounce 1.6s ease-in-out infinite;
    }

    @keyframes heroScrollBounce {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(5px); }
    }

    @media (min-width: 992px) {
        .hero-scroll-cue {
            display: inline-flex;
        }
    }

    /* Mobile/tablet: flip the desktop's left-aligned layout to centered,
       and reveal the logo above the eyebrow tagline. Placed before the
       height-tier queries below (same specificity throughout - .hero-*
       single-class selectors - so source order decides ties) so that on a
       short mobile viewport the height tier's own display:none for the
       logo still wins over this block's display:flex. */
    @media (max-width: 991.98px) {
        /* Anchored to the top of the hero instead of vertically centered -
           puts the (now larger) logo right under the sticky navbar rather
           than floating in the middle of the photo, on any phone/tablet
           tall enough that the content doesn't already fill the viewport. */
        .hero-section {
            justify-content: center;
            align-items: flex-start;
            text-align: center;
        }

        .hero-content {
            margin: 0 auto;
        }

        .hero-logo-wrap {
            display: flex;
            margin-bottom: 1.75rem;
        }

        /* Mobile/tablet logo is now sized independently of the (hidden)
           desktop base value instead of inheriting it - bumped up further
           so it reads as a real brand mark anchored at the top of the
           hero, not an afterthought above the tagline. */
        .hero-logo-badge {
            width: 130px;
            height: 114px;
        }

        .hero-eyebrow-underline,
        .hero-divider,
        .hero-desc {
            margin-left: auto;
            margin-right: auto;
        }

        .hero-actions {
            justify-content: center;
        }
    }

    /* Height-based tiers (not width-based) - these respond directly to
       whatever vertical space is actually available, which is the real
       constraint behind "fits within 80-120% browser zoom" across
       different screens, OS display-scaling, and browser-chrome heights.
       Predicting available height from assumed device/zoom numbers proved
       unreliable in practice; reacting to the real rendered height via
       max-height media queries is correct by construction instead. */
    @media (max-height: 820px) {
        .hero-content {
            padding-top: 2rem;
            padding-bottom: 2.25rem;
        }

        .hero-logo-wrap {
            margin-bottom: 1rem;
        }

        .hero-logo-badge {
            width: 88px;
            height: 77px;
        }

        .hero-eyebrow {
            font-size: 0.78rem;
        }

        .hero-eyebrow-underline {
            margin-bottom: 0.85rem;
        }

        .hero-heading {
            font-size: clamp(2rem, 3.8vw, 3rem);
            margin-bottom: 0.6rem;
        }

        .hero-divider {
            margin-bottom: 0.4rem;
        }

        .hero-tagline {
            font-size: clamp(1.05rem, 1.6vw, 1.35rem);
            margin-bottom: 0.4rem;
        }

        .hero-desc {
            font-size: 1rem;
            margin-bottom: 1rem;
        }

        .btn-hero,
        .btn-hero-outline {
            padding: 0.55rem 1.5rem;
            font-size: 1rem;
        }

        .hero-scroll-cue {
            display: none;
        }
    }

    @media (max-height: 560px) {
        .hero-desc {
            display: none;
        }

        .hero-logo-wrap {
            display: none;
        }
    }

    @media (max-width: 575.98px) {
        .hero-content {
            padding-left: 1.25rem;
            padding-right: 1.25rem;
        }

        .hero-actions {
            flex-direction: column;
        }

        .btn-hero,
        .btn-hero-outline {
            width: 100%;
            justify-content: center;
        }
    }

    /* About Us image - two photos auto-swapping every 5s via a pure CSS
       crossfade (no JS dependency, so it can never silently stop working
       the way a JS-driven slider could if a script error elsewhere on the
       page halted execution). Both images are stacked absolutely and share
       one 10s keyframe loop; the second is offset by a negative half-cycle
       delay so it's always showing the opposite half of the loop from the
       first - a short overlap (the 45-50% / 95-100% keyframe range) turns
       the swap into a brief fade instead of an instant cut. */
    .about-image-slider {
        position: relative;
        overflow: hidden;
        min-height: 320px;
    }

    .about-slide {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        opacity: 0;
        animation: aboutSlideFade 10s ease-in-out infinite;
    }

    .about-slide-1 {
        animation-delay: 0s;
    }

    .about-slide-2 {
        animation-delay: -5s;
    }

    @keyframes aboutSlideFade {
        0%, 45% { opacity: 1; }
        50%, 95% { opacity: 0; }
        100% { opacity: 1; }
    }

    @media (prefers-reduced-motion: reduce) {
        .about-slide {
            animation: none;
        }

        .about-slide-1 {
            opacity: 1;
        }

        .about-slide-2 {
            opacity: 0;
        }
    }

    /* Contact section - condensed 4-item info grid (address/phone/email/
       hours) followed by the map preview underneath, reusing the shared
       .contact-card/.contact-info-item styling from layouts/public.blade.php.
       Different shape from the dedicated Contact page's 6-item/3-column/
       2-row grid (this one is 1/2/4 columns across breakpoints), so the
       divider math needs its own rules even though the base look is shared. */
    @media (min-width: 576px) and (max-width: 991.98px) {
        .home-contact-info-strip .contact-info-item {
            border-left: 1px solid rgba(0, 0, 0, 0.06);
        }

        .home-contact-info-strip .contact-info-item:nth-child(odd) {
            border-left: none;
        }

        .home-contact-info-strip .contact-info-item:nth-child(3),
        .home-contact-info-strip .contact-info-item:nth-child(4) {
            border-bottom: none;
        }
    }

    @media (min-width: 992px) {
        .home-contact-info-strip .contact-info-item {
            border-bottom: none;
            border-left: 1px solid rgba(0, 0, 0, 0.06);
        }

        .home-contact-info-strip .contact-info-item:first-child {
            border-left: none;
        }
    }

    /* Promotions & Discounts cards - an optional cover image, then a badge
       (the one thing a guest scans for first), then the offer's own
       details below it. Every real Promotion row is an amenity-bundle
       package (discount-type promotions were deactivated on the admin
       side), so the badge always reads "Free Add-Ons Included", never a
       computed percentage/amount - Discount rows (Senior/PWD/Student,
       applied at billing) keep the original off-amount badge below since
       that's a real percentage/fixed value on that model. */
    .promo-offer-card {
        border-left: 4px solid var(--primary-color);
        transition: transform 0.2s ease;
        overflow: hidden;
    }

    .promo-offer-card:hover {
        transform: translateY(-4px);
    }

    .promo-offer-img {
        width: 100%;
        height: 160px;
        object-fit: cover;
    }

    .promo-offer-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        align-self: flex-start;
        background-color: var(--primary-color);
        color: #fff;
        font-weight: 800;
        font-size: 0.85rem;
        padding: 0.35rem 0.85rem;
        border-radius: var(--radius-pill);
    }

    .promo-offer-amenity-list {
        list-style: none;
        padding-left: 0;
        margin-bottom: 0.5rem;
    }

    .promo-offer-amenity-list li {
        padding-left: 1.35rem;
        position: relative;
    }

    .promo-offer-amenity-list li::before {
        content: '\f00c';
        font-family: 'Font Awesome 6 Free';
        font-weight: 900;
        color: var(--primary-color);
        position: absolute;
        left: 0;
        font-size: 0.75rem;
        top: 0.2rem;
    }

    /* Announcements cards - a news-style card with an optional cover image
       up top, kept compact (fixed image height, clamped excerpt) since
       this is a "glance and click through" section, not the full content. */
    .announcement-card-img {
        width: 100%;
        height: 140px;
        object-fit: cover;
    }

    .announcement-card {
        transition: transform 0.2s ease;
    }

    .announcement-card:hover {
        transform: translateY(-4px);
    }

    /* Mobile App Promotion - the Velocity Suites logo in a large rounded
       card, standing in for a phone-mockup/screenshot visual without
       fabricating imagery that doesn't exist yet. */
    .mobile-app-logo-card {
        width: 260px;
        height: 260px;
        border-radius: 2rem;
    }

    .mobile-app-logo-img {
        max-width: 70%;
        max-height: 70%;
        object-fit: contain;
    }
</style>
@endpush

@section('content')
    <!-- Hero Section -->
    <section class="hero-section" id="home">
        <div class="hero-media">
            <img src="{{ asset('images/hero-building.jpg') }}" alt="Velocity Suites building">
        </div>
        <div class="hero-content">
            <div class="hero-logo-wrap">
                <span class="logo-badge hero-logo-badge">
                    <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
                </span>
            </div>
            <span class="hero-eyebrow">Comfort &bull; Convenience &bull; Hospitality</span>
            <span class="hero-eyebrow-underline"></span>
            <h1 class="hero-heading">Welcome to<br><span class="text-brand">Velocity</span> Suites</h1>
            <div class="hero-divider"></div>
            <p class="hero-tagline mb-0">Your Comfort <span class="text-brand">is our Service</span></p>
            <p class="hero-desc">Experience comfort, convenience, and exceptional hospitality. Whether you're here for business or leisure, we're dedicated to making your stay memorable.</p>
            <div class="hero-actions">
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity btn-lg btn-hero">
                    Explore Rooms <i class="fas fa-arrow-right"></i>
                </a>
                <a href="{{ route('login') }}" class="btn btn-hero-outline">
                    Sign In <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <span class="hero-scroll-cue"><i class="fas fa-computer-mouse"></i> Scroll Down</span>
        </div>
    </section>

    <!-- Why Velocity Suites -->
    <section class="py-5 bg-light">
        <div class="container">
            <div class="row g-4 text-center">
                <div class="col-md-4">
                    <span class="highlight-icon-badge"><i class="fas fa-location-dot"></i></span>
                    <h3 class="h5 fw-bold">Prime Location</h3>
                    <p class="text-muted mb-0">Conveniently located near business hubs and local attractions.</p>
                </div>
                <div class="col-md-4">
                    <span class="highlight-icon-badge"><i class="fas fa-shield-halved"></i></span>
                    <h3 class="h5 fw-bold">Trusted Service</h3>
                    <p class="text-muted mb-0">Our friendly staff is committed to your comfort and satisfaction.</p>
                </div>
                <div class="col-md-4">
                    <span class="highlight-icon-badge"><i class="fas fa-concierge-bell"></i></span>
                    <h3 class="h5 fw-bold">Great Hospitality</h3>
                    <p class="text-muted mb-0">Experience exceptional service that makes you feel at home.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- Featured Rooms -->
    <section id="rooms" class="py-5">
        <div class="container">
            <div class="text-center mb-4">
                <span class="section-badge mb-3"><i class="fas fa-bolt"></i> Live Rates</span>
                <h2 class="mt-3">Featured <span class="text-brand">Rooms</span></h2>
                <p class="text-muted">Handpicked suites ready for your next getaway</p>
            </div>
            <div class="row g-4">
                @forelse($featuredRoomTypes ?? [] as $roomType)
                <div class="col-sm-6 col-lg-4">
                    <div class="room-card">
                        <img src="{{ $roomType->image_url }}"
                             alt="{{ $roomType->name }}" class="img-fluid" style="height: 200px; width: 100%; object-fit: cover;">
                        <div class="p-4">
                            <h5 class="fw-bold">{{ $roomType->name }}</h5>
                            <p class="mb-2 text-muted">
                                <i class="fas fa-user me-1 text-brand"></i> Up to {{ $roomType->capacity }} guests
                            </p>
                            @if($roomType->description)
                                <p class="small text-muted mb-2">{{ Str::limit($roomType->description, 80) }}</p>
                            @endif
                            <p class="room-price">₱{{ number_format($roomType->rate, 2) }} <small class="text-muted">/night</small></p>
                            <a href="{{ route('public.rooms.show', $roomType) }}" class="btn btn-outline-danger w-100">View Details</a>
                        </div>
                    </div>
                </div>
                @empty
                <div class="col-12 text-center">
                    <p class="text-muted">No room types available at the moment. Please check back later.</p>
                </div>
                @endforelse
            </div>
            <div class="text-center mt-5">
                <a href="{{ route('public.rooms.index') }}" class="btn btn-velocity">View All Rooms</a>
            </div>
        </div>
    </section>

    <!-- About Section -->
    <section id="about" class="py-5">
        <div class="container">
            <div class="row align-items-stretch g-5">
                <div class="col-lg-5">
                    <div class="about-image-slider rounded shadow w-100 h-100">
                        <img src="{{ asset('images/about-1.jpg') }}" alt="Velocity Suites guests" class="about-slide about-slide-1">
                        <img src="{{ asset('images/about-2.jpg') }}" alt="Velocity Suites guests" class="about-slide about-slide-2">
                    </div>
                </div>
                <div class="col-lg-7 d-flex flex-column">
                    <h2 class="mb-4">About <span class="text-brand">Us</span></h2>
                    <p class="mb-3 text-muted">
                        Welcome to Velocity Suites, a comfortable and welcoming accommodation establishment located
                        along Allah Valley Drive, Surallah, South Cotabato, Philippines. We are committed to providing
                        guests with a convenient, relaxing, and memorable stay through quality accommodations and
                        friendly service.
                    </p>
                    <p class="mb-3 text-muted">
                        Velocity Suites offers a variety of guest rooms designed to provide comfort and convenience
                        for both short and extended stays. With our thoughtfully prepared facilities and guest-focused
                        services, we strive to create a pleasant environment where every guest can feel at home.
                    </p>
                    <p class="mb-3 text-muted">
                        Our goal is to deliver a smooth and enjoyable accommodation experience by combining
                        comfortable rooms, accessible services, and attentive guest assistance. We continuously work
                        to improve our facilities and services to meet the changing needs and expectations of our
                        guests.
                    </p>
                    <p class="mb-4 text-muted">
                        At Velocity Suites, your comfort, satisfaction, and memorable experience are our priority.
                        Whether you are visiting Surallah for business, leisure, or other purposes, we are pleased to
                        welcome you and make your stay a comfortable one.
                    </p>
                    <p class="fw-bold text-brand mb-4">Velocity Suites &mdash; Your comfort, our priority.</p>
                    <a href="{{ route('public.about') }}" class="btn btn-velocity mt-auto align-self-start">
                        Learn More <i class="fas fa-arrow-right"></i>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- Amenities - dynamically sourced from the System Administrator's
         Amenity Management, same active-only query the public Amenities
         page and the mobile app's Api\CatalogController use, so this never
         drifts out of sync with what's actually configured. -->
    <section id="amenities" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-spa"></i> Hotel Amenities</span>
                <h2 class="mt-3">Our <span class="text-brand">Amenities</span></h2>
                <p class="text-muted">Everything you need for a comfortable, effortless stay</p>
            </div>
            @forelse($amenities ?? [] as $amenity)
                @if($loop->first)<div class="row g-4">@endif
                <div class="col-sm-6 col-md-4">
                    <div class="feature-card h-100" style="cursor: pointer;"
                         data-amenity-detail
                         data-amenity-name="{{ $amenity->amenity_name }}"
                         data-amenity-category="{{ $amenity->category }}"
                         data-amenity-description="{{ $amenity->description }}"
                         data-amenity-pricing="{{ $amenity->charge > 0 ? 'paid' : 'complimentary' }}"
                         data-amenity-charge="{{ number_format($amenity->charge, 2) }}"
                         data-amenity-stock="{{ $amenity->quantity }}"
                         role="button" tabindex="0">
                        <i class="fas {{ $amenity->icon }} feature-icon"></i>
                        <h4>{{ $amenity->amenity_name }}</h4>
                        @if($amenity->description)
                            <p class="text-muted small mb-2">{{ Str::limit($amenity->description, 100) }}</p>
                        @endif
                        <p class="small fw-bold text-brand mb-0">View Details <i class="fas fa-arrow-right"></i></p>
                    </div>
                </div>
                @if($loop->last)</div>@endif
            @empty
                <p class="text-center text-muted mb-0">No amenities are currently available. Please check back later.</p>
            @endforelse
            <div class="text-center mt-5">
                <a href="{{ route('public.amenities.index') }}" class="btn btn-velocity">
                    View Amenities <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <!-- Promotions & Discounts - dynamically sourced from the System
         Administrator's Promotion/Discount management, same active +
         date-range filters as Api\CatalogController. Renders nothing (not
         an empty shell) when both collections are empty. -->
    @if(($promotions ?? collect())->isNotEmpty() || ($discounts ?? collect())->isNotEmpty())
    <section id="promotions" class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-tag"></i> Special Offers</span>
                <h2 class="mt-3">Promotions <span class="text-brand">&amp; Discounts</span></h2>
                <p class="text-muted">Take advantage of our current offers - available for a limited time</p>
            </div>
            <div class="row g-4 justify-content-center">
                @foreach($promotions ?? [] as $promotion)
                    <div class="col-sm-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100 promo-offer-card">
                            @if($promotion->image_url)
                                <img src="{{ $promotion->image_url }}" alt="{{ $promotion->promo_name }}" class="promo-offer-img">
                            @endif
                            <div class="card-body p-4 d-flex flex-column">
                                <span class="promo-offer-badge mb-3"><i class="fas fa-gift"></i> Free Add-Ons Included</span>
                                <h5 class="fw-bold">{{ $promotion->promo_name }}</h5>
                                @if($promotion->description)
                                    <p class="text-muted small mb-2">{{ Str::limit($promotion->description, 90) }}</p>
                                @endif
                                @if($promotion->amenities->isNotEmpty())
                                    <ul class="promo-offer-amenity-list text-muted small">
                                        @foreach($promotion->amenities as $amenity)
                                            <li>{{ $amenity->pivot->quantity }}&times; {{ $amenity->amenity_name }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if($promotion->roomType)
                                    <p class="text-muted small mb-2">
                                        <i class="fas fa-door-open text-brand"></i> {{ $promotion->roomType->name }}
                                    </p>
                                @endif
                                <p class="text-muted small mb-0 mt-auto">
                                    <i class="fas fa-calendar-alt text-brand"></i>
                                    Valid {{ $promotion->start_date->format('M d') }} - {{ $promotion->end_date->format('M d, Y') }}
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
                @foreach($discounts ?? [] as $discount)
                    <div class="col-sm-6 col-lg-4">
                        <div class="card border-0 shadow-sm h-100 promo-offer-card">
                            <div class="card-body p-4 d-flex flex-column">
                                <span class="promo-offer-badge mb-3">
                                    @if($discount->discount_type === 'percentage')
                                        {{ rtrim(rtrim(number_format($discount->value, 2), '0'), '.') }}% OFF
                                    @else
                                        &#8369;{{ number_format($discount->value, 2) }} OFF
                                    @endif
                                </span>
                                <h5 class="fw-bold">{{ $discount->name }}</h5>
                                @if($discount->description)
                                    <p class="text-muted small mb-0">{{ Str::limit($discount->description, 90) }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <!-- Announcements - published, "public"-audience rows only (see
         App\Models\Announcement::visibleTo()), same role-scoped mechanism
         the Guest/Manager/Receptionist dashboards and the mobile app use
         for their own audiences. Each card's full content lives in a
         Bootstrap modal instead of a second route, so "Read More" never
         leaves the page. -->
    @if(($announcements ?? collect())->isNotEmpty())
    <section id="announcements" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-bullhorn"></i> Latest News</span>
                <h2 class="mt-3">Announcements <span class="text-brand">&amp; Updates</span></h2>
                <p class="text-muted">Stay up to date with what's happening at Velocity Suites</p>
            </div>
            <div class="row g-4 justify-content-center">
                @foreach($announcements as $announcement)
                    <div class="col-sm-6 col-lg-3">
                        <div class="card border-0 shadow-sm h-100 announcement-card">
                            @if($announcement->first_image_url)
                                <img src="{{ $announcement->first_image_url }}" alt="{{ $announcement->title }}" class="announcement-card-img">
                            @endif
                            <div class="card-body d-flex flex-column text-center">
                                <h6 class="fw-bold mb-3">{{ $announcement->title }}</h6>
                                <button type="button" class="btn btn-outline-danger btn-sm w-100 mt-auto" data-bs-toggle="modal" data-bs-target="#announcementModal{{ $announcement->id }}">
                                    View Details <i class="fas fa-arrow-right ms-1"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="modal fade" id="announcementModal{{ $announcement->id }}" tabindex="-1" aria-labelledby="announcementModalLabel{{ $announcement->id }}" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title" id="announcementModalLabel{{ $announcement->id }}">{{ $announcement->title }}</h5>
                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                </div>
                                <div class="modal-body">
                                    @if($announcement->first_image_url)
                                        <img src="{{ $announcement->first_image_url }}" alt="{{ $announcement->title }}" class="img-fluid rounded mb-3">
                                    @endif
                                    <p class="text-muted small mb-3">
                                        <i class="fas fa-calendar-alt"></i>
                                        {{ optional($announcement->published_at)->format('M d, Y') ?? $announcement->created_at->format('M d, Y') }}
                                    </p>
                                    <p class="mb-0" style="white-space: pre-line;">{{ $announcement->content }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
    @endif

    <!-- Mobile App Promotion - only rendered when a real download
         destination is configured (services.mobile_app.download_url); no
         placeholder/dead button is ever shown when it isn't set. -->
    @if(config('services.mobile_app.download_url'))
    <section id="mobile-app" class="py-5">
        <div class="container">
            <div class="row align-items-center g-5">
                <div class="col-lg-5 text-center">
                    <div class="card border-0 shadow mobile-app-logo-card mx-auto">
                        <div class="card-body d-flex align-items-center justify-content-center">
                            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites Mobile App" class="mobile-app-logo-img">
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <span class="section-badge mb-3"><i class="fas fa-mobile-alt"></i> Mobile App</span>
                    <h2 class="mt-3">Book, Manage, and Pay <span class="text-brand">On the Go</span></h2>
                    <p class="text-muted mb-4">
                        The Velocity Suites mobile app puts booking, reservations, payments, and hotel updates right
                        in your pocket. Browse rooms, track your stay, and get notified about the latest promotions
                        and announcements - all from your phone.
                    </p>
                    <a href="{{ config('services.mobile_app.download_url') }}" target="_blank" rel="noopener" class="btn btn-velocity btn-lg">
                        <i class="fas fa-download"></i> Download Mobile App
                    </a>
                </div>
            </div>
        </div>
    </section>
    @endif

    <!-- Contact Us - condensed info grid (address/phone/email/hours) first,
         map preview underneath, then a link to the full dedicated Contact
         Us page for Event Booking hours/website - gives Home page visitors
         the essentials + location at a glance without fully duplicating
         that page's complete content. -->
    <section id="contact" class="py-5 bg-light">
        <div class="container">
            <div class="text-center mb-5">
                <span class="section-badge mb-3"><i class="fas fa-envelope-open-text"></i> Get in Touch</span>
                <h2 class="mt-3">Contact <span class="text-brand">Us</span></h2>
                <p class="text-muted">Velocity Suites Hotel - we'd love to hear from you.</p>
            </div>

            <div class="row justify-content-center">
                <div class="col-lg-10">
                    <div class="card border-0 shadow contact-card overflow-hidden">
                        <div class="home-contact-info-strip">
                            <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-4 g-0">
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
                                    <p class="contact-info-value">Front Desk: 24/7<br>Check-In: From 2:00 PM<br>Check-Out: Until 12:00 PM</p>
                                </div>
                            </div>
                        </div>

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
                    </div>
                </div>
            </div>

            <div class="text-center mt-4">
                <a href="{{ route('public.contact') }}" class="btn btn-velocity btn-lg">
                    View Full Contact Details <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </div>
    </section>

    <x-amenity-detail-modal />
@endsection

