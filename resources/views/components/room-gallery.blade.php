@props(['images' => [], 'title' => 'Room', 'id' => 'roomGallery', 'thumbOnly' => false])

@once
<script src="{{ asset('js/photo-viewer.js') }}?v={{ filemtime(public_path('js/photo-viewer.js')) }}"></script>
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
        <div class="room-gallery-thumb-trigger" data-vs-view="{{ $id }}"
             role="button" tabindex="0" aria-label="View {{ $title }} photos">
            <img src="{{ $images[0]['url'] }}" alt="{{ $title }} photo 1" loading="lazy" onerror="this.onerror=null;this.style.display='none';this.parentElement.classList.add('bg-secondary');">
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
                                 onerror="this.onerror=null;this.src='data:image/svg+xml;utf8,%3Csvg xmlns=%27http://www.w3.org/2000/svg%27 width=%27800%27 height=%27500%27%3E%3Crect width=%27100%25%27 height=%27100%25%27 fill=%27%23e9ecef%27/%3E%3Ctext x=%2750%25%27 y=%2750%25%27 fill=%27%236c757d%27 font-family=%27sans-serif%27 font-size=%2732%27 text-anchor=%27middle%27%3EImage unavailable%3C/text%3E%3C/svg%3E';">
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
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-vs-view="{{ $id }}">
                        <i class="fas fa-expand"></i> Full Screen
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Full screen: the shared viewer (public/js/photo-viewer.js) shows the original file whole, with a counter, arrows, swipe, Esc and an "Image unavailable" state. --}}
    <script type="application/json" id="{{ $id }}Photos">@json(collect($images)->map(fn ($im) => ['url' => $im['url'], 'label' => $im['room_label'] ?? ''])->values())</script>
    <script>
        (function () {
            var photos = JSON.parse(document.getElementById('{{ $id }}Photos').textContent), title = @json($title);
            function open(i) { if (window.VSPhotoViewer) window.VSPhotoViewer.open(photos, i, { title: title }); }
            var inlineEl = document.getElementById('{{ $id }}');
            document.querySelectorAll('[data-vs-view="{{ $id }}"]').forEach(function (el) {
                el.addEventListener('click', function () {
                    var idx = 0;
                    if (inlineEl) { inlineEl.querySelectorAll('.carousel-item').forEach(function (it, k) { if (it.classList.contains('active')) idx = k; }); }
                    open(idx);
                });
                el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); el.click(); } });
            });
            if (inlineEl) {
                inlineEl.querySelectorAll('.room-gallery-img').forEach(function (img, idx) { img.addEventListener('click', function () { open(idx); }); });
            }
        })();

        function roomGalleryTogglePlay(id) {
            var el = document.getElementById(id);
            if (!el || typeof bootstrap === 'undefined') return;
            var carousel = bootstrap.Carousel.getOrCreateInstance(el);
            var btn = document.getElementById(id + 'PlayPause');
            var icon = btn.querySelector('i');
            var label = btn.querySelector('span');
            if (icon.classList.contains('fa-pause')) { carousel.pause(); icon.classList.replace('fa-pause', 'fa-play'); label.textContent = 'Play'; }
            else { carousel.cycle(); icon.classList.replace('fa-play', 'fa-pause'); label.textContent = 'Pause'; }
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
