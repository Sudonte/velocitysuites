@extends('layouts.public')

@section('title', 'Velocity Suites - Home')

@push('head')
<link rel="preload" as="image" type="image/webp" href="{{ asset('images/hero-building.webp') }}"
      imagesrcset="{{ asset('images/hero-building-640.webp') }} 640w, {{ asset('images/hero-building.webp') }} 960w" imagesizes="100vw" fetchpriority="high">
@endpush

@push('styles')
<style>
    /* Hero: "Velocity Suites" (the only h1), the tagline, one short line, then the Android download; one full-bleed photo with a dark
       overlay (so the text is light-on-dark in both site themes). One grid: content is at most 1200px wide and centred, with
       16px side padding on phones, 24px on tablets, 32px on desktop. Under 992px it is a single
       column (headline, tagline, line, download, phone); from 992px two columns; the QR code from 1200px.
       Spacing uses an 8px scale. */
    .hero-section {
        position: relative;
        background-color: #0a0a0a;
        overflow: hidden;
        display: flex;
        align-items: center;
        text-align: left;
    }

    .hero-media {
        position: absolute;
        inset: 0;
        z-index: 0;
    }

    .hero-media picture,
    .hero-media img {
        display: block;
        width: 100%;
        height: 100%;
    }

    .hero-media img {
        object-fit: cover;
    }

    /* Uniformly dark behind the text (body text stays well above WCAG AA 4.5:1), a little lighter on the photo side */
    .hero-media::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(180deg, rgba(6, 6, 8, 0.86) 0%, rgba(6, 6, 8, 0.82) 50%, rgba(6, 6, 8, 0.9) 100%);
        pointer-events: none;
    }

    .hero-app {
        position: relative;
        z-index: 2;
        width: 100%;
        max-width: 1200px;
        margin: 0 auto;
        padding: 32px 16px 40px;
        color: #fff;
        display: grid;
        grid-template-columns: minmax(0, 1fr);
        gap: 32px;
        align-items: center;
    }

    .hero-app-copy {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        min-width: 0;
        animation: heroAppFade 0.6s ease both;
    }

    .hero-app-visual {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 24px;
        animation: heroAppFade 0.6s ease 0.15s both;
    }

    @keyframes heroAppFade {
        from { opacity: 0; transform: translateY(12px); }
        to { opacity: 1; transform: none; }
    }

    /* Brand line: "Velocity Suites" in red (a lighter tint of the brand red so small text keeps 4.5:1 on the dark photo) over the tagline */



    .hero-app-title {
        font-size: clamp(2.25rem, 1.8rem + 2vw, 2.75rem); /* 36px to 44px on phones and tablets; 56px to 80px from 992px */
        font-weight: 800;
        line-height: 1.05;
        color: #fff;
        margin: 0 0 16px;
    }

    /* "Your comfort is our service": the tagline, directly under the headline */
    .hero-app-tagline {
        font-size: clamp(1.05rem, 0.95rem + 0.5vw, 1.4rem);
        font-weight: 600;
        color: #f2767e; /* a lighter tint of the brand red, 4.5:1 or better on the dark photo */
        margin: 0 0 16px;
    }

    .hero-app-desc {
        color: rgba(255, 255, 255, 0.88);
        font-size: clamp(1rem, 0.95rem + 0.25vw, 1.125rem);
        line-height: 1.6;
        max-width: 560px;
        margin: 0 0 24px;
    }

    /* Features: 6 items, 2 columns (1 under 400px) */





    /* Download block. Phone order: download, version line, install help, Explore rooms / Sign in, trust row.
       Desktop: the three buttons on one row, then the version line, install help and trust row. */
    .hero-app-cta {
        display: flex;
        flex-direction: column;
        width: 100%;
        max-width: 440px;
        margin-top: 32px;
    }

    .hero-app-secondary { order: 1; }
    .hero-app-download,
    .hero-app-soon { order: 2; }
    .hero-app-info { order: 3; }
    .hero-app-install { order: 4; }

    .hero-app-download,
    .hero-btn-outline {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        min-height: 52px;
        padding: 8px 16px;
        font-size: 1rem;
        font-weight: 600;
        line-height: 1.2;
        white-space: nowrap;
        border-radius: var(--radius-btn);
        text-align: center;
    }

    .hero-app-download {
        width: 100%;
        min-height: 52px;
    }

    .hero-btn-outline {
        background: transparent;
        color: #fff;
        border: 2px solid rgba(255, 255, 255, 0.6);
        transition: background-color 0.2s ease, border-color 0.2s ease;
    }

    .hero-btn-outline:hover {
        background: rgba(255, 255, 255, 0.12);
        border-color: #fff;
        color: #fff;
    }

    .hero-app-download:focus-visible,
    .hero-btn-outline:focus-visible,
    .hero-app-install summary:focus-visible {
        outline: 2px solid #fff;
        outline-offset: 3px;
    }

    .hero-app-secondary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .hero-app-download,
    .hero-app-soon { margin-top: 12px; }

    .hero-app-info {
        margin: 8px 0 0;
        font-size: 0.85rem;
        color: rgba(255, 255, 255, 0.82);
    }

    .hero-app-soon {
        margin: 0;
        padding: 12px 16px;
        border-radius: var(--radius-btn);
        background: rgba(255, 255, 255, 0.12);
        font-weight: 600;
    }

    .hero-app-install {
        margin-top: 8px;
        font-size: 0.9rem;
        color: rgba(255, 255, 255, 0.92);
    }

    .hero-app-install summary {
        cursor: pointer;
        font-weight: 600;
        min-height: 44px;
        display: flex;
        align-items: center;
        width: fit-content;
        list-style: none;
    }

    .hero-app-install summary::-webkit-details-marker {
        display: none;
    }

    .hero-app-install summary::before {
        content: "\f054";
        font-family: "Font Awesome 6 Free";
        font-weight: 900;
        font-size: 0.7rem;
        margin-right: 8px;
        transition: transform 0.2s ease;
    }

    .hero-app-install[open] summary::before {
        transform: rotate(90deg);
    }

    .hero-app-install ol {
        margin: 0 0 8px;
        padding-left: 20px;
    }

    .hero-app-install li {
        margin-bottom: 8px;
    }

    .hero-app-install p {
        margin: 0;
        color: rgba(255, 255, 255, 0.78);
    }



    /* Phone frame, CSS only: thin dark bezel, rounded corners, a punch-hole camera and a soft shadow. The screen is exactly
       the screenshot's own shape (1080 x 2172), so it fills the screen with no stretching and no gaps. */
    .hero-phone {
        position: relative;
        width: min(70vw, 280px);
        padding: 7px;
        border-radius: 38px;
        background: #0e1014;
        border: 1px solid #343842;
        box-shadow: 0 30px 70px rgba(0, 0, 0, 0.55), 0 8px 20px rgba(0, 0, 0, 0.35);
        animation: heroPhoneFloat 7s ease-in-out infinite;
    }

    .hero-phone-screen {
        position: relative;
        width: 100%;
        aspect-ratio: 1080 / 2172;
        border-radius: 31px;
        overflow: hidden;
        background: #3a0d12;
    }

    .hero-phone-screen picture,
    .hero-phone-screen img {
        display: block;
        width: 100%;
        height: 100%;
    }

    .hero-phone-screen img {
        object-fit: cover;
        object-position: top center;
    }

    .hero-phone-screen::after {
        content: "";
        position: absolute;
        top: 6px;
        left: 50%;
        width: 8px;
        height: 8px;
        margin-left: -4px;
        border-radius: 50%;
        background: #05060a;
        box-shadow: 0 0 0 1.5px rgba(255, 255, 255, 0.12);
    }

    @keyframes heroPhoneFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-8px); }
    }

    .hero-app-qr {
        display: none;
    }


    @media (min-width: 576px) {
        .hero-app {
            padding-left: 24px;
            padding-right: 24px;
        }
    }

    @media (min-width: 768px) {
        .hero-phone {
            width: 230px;
        }
    }

    @media (min-width: 992px) {
        .hero-section {
            min-height: min(100vh, 960px);
            min-height: min(100dvh, 960px);
        }

        .hero-media::after {
            background: linear-gradient(90deg, rgba(6, 6, 8, 0.92) 0%, rgba(6, 6, 8, 0.82) 55%, rgba(6, 6, 8, 0.62) 100%);
        }

        .hero-app {
            grid-template-columns: minmax(0, 1.2fr) minmax(0, 0.8fr);
            gap: 48px;
            padding: 48px 32px 56px;
        }

        .hero-app-title {
            font-size: clamp(3.5rem, 2.2rem + 3vw, 5rem); /* 56px to 80px */
        }

        .hero-btn-outline {
            padding-left: 16px;
            padding-right: 16px;
        }

        .hero-phone {
            width: 250px;
        }
    }

    /* Short desktop screens (laptops): tighter, so the download button is still in the first view */
    @media (min-width: 992px) and (max-width: 1199.98px) and (max-height: 820px) {
        .hero-app {
            padding-top: 24px;
            padding-bottom: 32px;
        }


        .hero-app-title {
            margin-bottom: 4px;
        }

        .hero-app-tagline {
            margin-bottom: 8px;
        }

        .hero-app-desc {
            max-width: 640px;
            margin-bottom: 16px;
        }


        .hero-app-cta {
            margin-top: 24px;
        }
    }

    @media (min-width: 1200px) {
        .hero-app-qr:not([hidden]) {
            display: block;
            width: 168px;
            padding: 16px;
            border-radius: var(--radius-card);
            background: #fff;
            color: #1a1a1a;
            text-align: center;
            box-shadow: var(--shadow-md);
        }

        .hero-app-qr-image svg {
            width: 100%;
            height: auto;
            display: block;
        }

        .hero-app-qr p {
            margin: 8px 0 0;
            font-size: 0.85rem;
            font-weight: 600;
            line-height: 1.3;
        }
    }

    /* Laptops and desktops (1200px and up): the hero fills the window under the sticky navbar (100svh minus the
       66px bar), on a wider 1400px grid (55% copy / 45% phone, both vertically centred). Sizes grow with the
       screen: the headline about 40px at 1280 to 64px at 1920, the phone about 280px at 1366 to 360px at 1920 and
       more, spacing follows the window height. Phones and tablets keep the layout above. */
    @media (min-width: 1200px) {
        .hero-section {
            --hero-nav: 66px;
            --hero-gap: clamp(16px, 2.2vh, 32px);
            min-height: calc(100vh - var(--hero-nav));
        }

        @supports (height: 100svh) {
            .hero-section {
                min-height: calc(100svh - var(--hero-nav));
            }
        }

        .hero-app {
            max-width: 1440px;
            grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
            gap: clamp(32px, 3.5vw, 64px);
            padding: clamp(24px, 3.5vh, 48px) clamp(32px, 4vw, 64px);
        }




        .hero-app-title {
            font-size: clamp(3.5rem, 1.6rem + 3.2vw, 5rem);
            margin-bottom: calc(var(--hero-gap) * 0.25);
        }

        .hero-app-tagline {
            font-size: clamp(1.1rem, 0.9vw + 0.6rem, 1.6rem);
            margin-bottom: calc(var(--hero-gap) * 0.5);
        }

        .hero-app-desc {
            font-size: clamp(1rem, 0.78vw + 7px, 1.375rem);
            max-width: 40em;
            margin-bottom: var(--hero-gap);
        }





        .hero-app-cta {
            margin-top: calc(var(--hero-gap) * 1.25);
        }

        .hero-app-download,
        .hero-btn-outline {
            min-height: clamp(48px, 2.5vw + 16px, 56px);
            font-size: clamp(1rem, 0.3vw + 0.85rem, 1.125rem);
        }

        .hero-app-info {
            font-size: clamp(0.85rem, 0.2vw + 0.7rem, 1rem);
        }


        .hero-app-visual {
            gap: clamp(16px, 1.5vw, 24px);
        }

        /* about 280px wide at 1366, 360px at 1920 and up; never taller than the space under the navbar */
        .hero-phone {
            flex: 0 0 auto;
            width: min(clamp(250px, 82.8px + 14.44vw, 360px), calc((100vh - var(--hero-nav) - 96px) * 0.49));
        }

        @supports (height: 100svh) {
            .hero-phone {
                width: min(clamp(250px, 82.8px + 14.44vw, 360px), calc((100svh - var(--hero-nav) - 96px) * 0.49));
            }
        }

        .hero-app-qr:not([hidden]) {
            flex: 0 0 auto;
            width: clamp(140px, 3vw + 100px, 160px);
        }
    }

    /* Short laptop screens (1366 x 768, 1280 x 720): tighter spacing so everything still fits without scrolling */
    @media (min-width: 1200px) and (max-height: 800px) {
        .hero-section {
            --hero-gap: 14px;
        }

        .hero-app {
            padding-top: 16px;
            padding-bottom: 16px;
        }

        .hero-app-desc {
            max-width: 46em;
        }

        .hero-app-install summary {
            min-height: 36px;
        }

        .hero-app-cta {
            margin-top: 20px;
        }
    }

    /* Below desktop the download comes before the features (desktop is the other way round) */
    @media (max-width: 991.98px) {
        .hero-app-cta { margin-top: 0; }
    }

    @media (max-width: 359.98px) {
        .hero-btn-outline {
            padding-left: 8px;
            padding-right: 8px;
            font-size: 0.9rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .hero-app-copy,
        .hero-app-visual,
        .hero-phone {
            animation: none;
        }

        .hero-btn-outline,
        .hero-app-install summary::before {
            transition: none;
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
    <!-- Hero Section: the Android app download (facts come from config/services.php -> App\Support\MobileApp) -->
    <section class="hero-section" id="home">
        <div class="hero-media">
            <picture>
                <source type="image/webp" srcset="{{ asset('images/hero-building-640.webp') }} 640w, {{ asset('images/hero-building.webp') }} 960w" sizes="100vw">
                <img src="{{ asset('images/hero-building.jpg') }}"
                     srcset="{{ asset('images/hero-building-640.jpg') }} 640w, {{ asset('images/hero-building.jpg') }} 960w" sizes="100vw"
                     width="960" height="960" alt="Velocity Suites building" fetchpriority="high" decoding="async">
            </picture>
        </div>
        <div class="hero-app">
            <div class="hero-app-copy">
                <h1 class="hero-app-title">Velocity Suites</h1>
                <p class="hero-app-tagline">Your comfort is our service</p>
                <p class="hero-app-desc">Book a room in minutes and manage your stay from our Android app.</p>

                <div class="hero-app-cta">
                    <div class="hero-app-secondary">
                        <a href="{{ route('public.rooms.index') }}" class="hero-btn-outline">
                            Explore rooms <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                        <a href="{{ route('login') }}" class="hero-btn-outline">
                            Sign in <i class="fas fa-arrow-right" aria-hidden="true"></i>
                        </a>
                    </div>

                    @if($appAvailable)
                        <a href="{{ route('app.download') }}" download="{{ $appDownloadName }}" class="btn btn-velocity hero-app-download">
                            <i class="fab fa-android" aria-hidden="true"></i> Download for Android
                        </a>
                        <p class="hero-app-info">{{ $appInfoLine }}</p>

                        <details class="hero-app-install">
                            <summary>How to install</summary>
                            <ol>
                                <li>Tap &ldquo;Download for Android&rdquo; and open the downloaded file.</li>
                                <li>If asked, allow your browser to install unknown apps (Settings &rarr; Install unknown apps).</li>
                                <li>Tap Install, then open Velocity Suites and sign in or create an account.</li>
                            </ol>
                            <p>Your phone may show a security warning because the app isn&rsquo;t from the Google Play Store. This is expected.</p>
                        </details>
                    @else
                        <p class="hero-app-soon">The app download is coming soon.</p>
                    @endif
                </div>
            </div>

            <div class="hero-app-visual">
                @if($appAvailable)
                    <div class="hero-app-qr" data-app-qr="{{ route('app.download') }}" hidden>
                        <div class="hero-app-qr-image" data-app-qr-image></div>
                        <p>Scan to download on your phone</p>
                    </div>
                @endif
                <div class="hero-phone">
                    <div class="hero-phone-screen">
                        <picture>
                            <source type="image/webp" srcset="{{ asset('images/app-landing.webp') }}">
                            <img src="{{ asset('images/app-landing.png') }}" width="1080" height="2172" alt="Velocity Suites Android app home screen" loading="lazy" decoding="async">
                        </picture>
                    </div>
                </div>
            </div>
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
                                <i class="fas fa-user me-1 text-brand"></i> {{ $roomType->capacity_label }}
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
                         data-amenity-stock="{{ $amenity->is_unlimited ? 'Unlimited' : $amenity->quantity }}"
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
                                @if($promotion->hasDiscount())
                                    <span class="promo-offer-badge mb-3"><i class="fas fa-percent"></i> {{ $promotion->discountLabel() }}{{ $promotion->roomType ? ' · ' . $promotion->roomType->name : '' }}{{ $promotion->amenities->isNotEmpty() ? ' + free add-ons' : '' }}</span>
                                @else
                                    <span class="promo-offer-badge mb-3"><i class="fas fa-gift"></i> Free Add-Ons Included</span>
                                @endif
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

@if($appAvailable)
@push('scripts')
    <script src="{{ asset('js/vendor/qrcode-generator-1.4.4.js') }}" defer></script>
    <script src="{{ asset('js/app-download-qr.js') }}" defer></script>
@endpush
@endif


