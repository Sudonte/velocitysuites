@props(['images' => [], 'title' => 'Room', 'id' => 'roomGallery', 'thumbOnly' => false])

@once
<style>
    .room-gallery-img {
        height: 420px;
        width: 100%;
        object-fit: cover;
        cursor: zoom-in;
    }
    @media (max-width: 767.98px) {
        .room-gallery-img { height: 260px; }
    }
    .room-gallery-fullscreen-img {
        max-height: 90vh;
        max-width: 95vw;
        width: auto;
        object-fit: contain;
        margin: 0 auto;
    }
    .room-gallery-empty {
        height: 400px;
    }
    @media (max-width: 767.98px) {
        .room-gallery-empty { height: 240px; }
    }
    .room-gallery-room-label {
        z-index: 3;
    }
    /* Compact clickable thumbnail trigger (thumbOnly mode) - individual
       room cards (admin/receptionist Room Type workspace) link straight
       into the same full-screen viewer without repeating a full inline
       carousel on every card. */
    .room-gallery-thumb-trigger {
        position: relative;
        display: block;
        border-radius: var(--radius-btn);
        overflow: hidden;
        cursor: pointer;
        height: 140px;
    }
    .room-gallery-thumb-trigger img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .room-gallery-thumb-count {
        position: absolute;
        bottom: 0;
        right: 0;
        margin: 0.4rem;
        z-index: 2;
    }
    .room-gallery-thumb-empty {
        height: 140px;
    }
</style>
@endonce

@if(count($images) > 0)
    @if($thumbOnly)
        <div class="room-gallery-thumb-trigger" data-bs-toggle="modal" data-bs-target="#{{ $id }}Modal"
             role="button" tabindex="0" aria-label="View {{ $title }} photos">
            <img src="{{ $images[0]['url'] }}" alt="{{ $title }} photo 1" loading="lazy">
            <span class="badge bg-dark bg-opacity-75 room-gallery-thumb-count">
                <i class="fas fa-images me-1"></i>{{ count($images) }}
            </span>
        </div>
    @else
        <div class="room-gallery-wrap mb-4">
            <div id="{{ $id }}" class="carousel slide rounded overflow-hidden shadow-sm" data-bs-interval="5000" data-bs-wrap="true">
                <div class="carousel-inner">
                    @foreach($images as $i => $image)
                        <div class="carousel-item {{ $i === 0 ? 'active' : '' }} position-relative">
                            @if(!empty($image['room_label']))
                                <span class="badge bg-dark bg-opacity-75 position-absolute top-0 start-0 m-2 room-gallery-room-label">
                                    <i class="fas fa-door-open me-1"></i>{{ $image['room_label'] }}
                                </span>
                            @endif
                            <img src="{{ $image['url'] }}" class="d-block room-gallery-img" alt="{{ $title }} photo {{ $i + 1 }}"
                                 loading="{{ $i === 0 ? 'eager' : 'lazy' }}"
                                 onerror="this.onerror=null;this.src='{{ asset('images/logo.jpg') }}';">
                        </div>
                    @endforeach
                </div>
                @if(count($images) > 1)
                    <button class="carousel-control-prev" type="button" data-bs-target="#{{ $id }}" data-bs-slide="prev">
                        <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Previous</span>
                    </button>
                    <button class="carousel-control-next" type="button" data-bs-target="#{{ $id }}" data-bs-slide="next">
                        <span class="carousel-control-next-icon" aria-hidden="true"></span>
                        <span class="visually-hidden">Next</span>
                    </button>
                    <div class="carousel-indicators">
                        @foreach($images as $i => $image)
                            <button type="button" data-bs-target="#{{ $id }}" data-bs-slide-to="{{ $i }}"
                                    class="{{ $i === 0 ? 'active' : '' }}" aria-label="Slide {{ $i + 1 }}"></button>
                        @endforeach
                    </div>
                @endif
            </div>
            <div class="d-flex justify-content-between align-items-center mt-2">
                <small class="text-muted">{{ count($images) }} photo{{ count($images) === 1 ? '' : 's' }}</small>
                <div>
                    @if(count($images) > 1)
                        <button type="button" class="btn btn-sm btn-outline-secondary" id="{{ $id }}PlayPause" onclick="roomGalleryTogglePlay('{{ $id }}')">
                            <i class="fas fa-pause"></i> <span>Pause</span>
                        </button>
                    @endif
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#{{ $id }}Modal">
                        <i class="fas fa-expand"></i> Full Screen
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Full-screen viewer: Next/Prev, 5s auto-advance, wraparound loop, Pause/Resume - always rendered, whether reached via the inline carousel above or a compact thumbnail trigger. -->
    <div class="modal fade" id="{{ $id }}Modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-fullscreen modal-dialog-centered m-0">
            <div class="modal-content bg-dark">
                <div class="modal-header border-0 pb-0">
                    <button type="button" class="btn-close btn-close-white ms-auto" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body d-flex align-items-center justify-content-center p-0">
                    <div id="{{ $id }}Full" class="carousel slide w-100" data-bs-interval="5000" data-bs-wrap="true">
                        <div class="carousel-inner">
                            @foreach($images as $i => $image)
                                <div class="carousel-item {{ $i === 0 ? 'active' : '' }} text-center position-relative">
                                    @if(!empty($image['room_label']))
                                        <span class="badge bg-dark bg-opacity-75 position-absolute top-0 start-0 m-3 room-gallery-room-label">
                                            <i class="fas fa-door-open me-1"></i>{{ $image['room_label'] }}
                                        </span>
                                    @endif
                                    <img src="{{ $image['url'] }}" class="room-gallery-fullscreen-img" alt="{{ $title }} photo {{ $i + 1 }}" loading="lazy">
                                </div>
                            @endforeach
                        </div>
                        @if(count($images) > 1)
                            <button class="carousel-control-prev" type="button" data-bs-target="#{{ $id }}Full" data-bs-slide="prev">
                                <span class="carousel-control-prev-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Previous</span>
                            </button>
                            <button class="carousel-control-next" type="button" data-bs-target="#{{ $id }}Full" data-bs-slide="next">
                                <span class="carousel-control-next-icon" aria-hidden="true"></span>
                                <span class="visually-hidden">Next</span>
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var inlineEl = document.getElementById('{{ $id }}');
            var fullEl = document.getElementById('{{ $id }}Full');
            var modalEl = document.getElementById('{{ $id }}Modal');
            if (typeof bootstrap === 'undefined') return;

            var fullCarousel = fullEl ? bootstrap.Carousel.getOrCreateInstance(fullEl, {interval: 5000, wrap: true}) : null;

            if (inlineEl) {
                var inlineCarousel = bootstrap.Carousel.getOrCreateInstance(inlineEl, {interval: 5000, wrap: true, ride: 'carousel'});

                // Clicking an inline slide opens the full-screen modal on the same image.
                inlineEl.querySelectorAll('.room-gallery-img').forEach(function (img, idx) {
                    img.addEventListener('click', function () {
                        if (fullCarousel) fullCarousel.to(idx);
                    });
                });
            }

            if (modalEl && fullCarousel) {
                modalEl.addEventListener('shown.bs.modal', function () { fullCarousel.cycle(); });
                modalEl.addEventListener('hidden.bs.modal', function () { fullCarousel.pause(); });
            }
        })();

        function roomGalleryTogglePlay(id) {
            var el = document.getElementById(id);
            if (!el || typeof bootstrap === 'undefined') return;
            var carousel = bootstrap.Carousel.getOrCreateInstance(el);
            var btn = document.getElementById(id + 'PlayPause');
            var icon = btn.querySelector('i');
            var label = btn.querySelector('span');

            if (icon.classList.contains('fa-pause')) {
                carousel.pause();
                icon.classList.replace('fa-pause', 'fa-play');
                label.textContent = 'Play';
            } else {
                carousel.cycle();
                icon.classList.replace('fa-play', 'fa-pause');
                label.textContent = 'Pause';
            }
        }
    </script>
@else
    @if($thumbOnly)
        <div class="bg-secondary d-flex align-items-center justify-content-center room-gallery-thumb-empty rounded" title="No gallery images available for {{ $title }} yet">
            <i class="fas fa-image text-white"></i>
        </div>
    @else
        <div class="card mb-4">
            <div class="bg-secondary d-flex align-items-center justify-content-center room-gallery-empty">
                <i class="fas fa-image text-white" style="font-size: 3.5rem;"></i>
            </div>
            <div class="card-body text-center text-muted">
                <p class="mb-0">No gallery images available for {{ $title }} yet.</p>
            </div>
        </div>
    @endif
@endif
