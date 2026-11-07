@extends('layouts.app')

@section('title', 'Announcements - Admin')
@section('livePage', '1')

@push('styles')
<style>
    .ann-filters .form-label { margin-bottom: .25rem; }
    .ann-filters .btn { height: calc(2.25rem + 2px); }
    .ann-chip { display: inline-flex; align-items: center; padding: .18rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
    .ann-chip.draft { background: #e9ecef; color: #343a40; }
    .ann-chip.scheduled { background: #dbeafe; color: #1e40af; }
    .ann-chip.published { background: #dcfce7; color: #166534; }
    .ann-chip.expired { background: #fef3c7; color: #8a5a00; }
    .ann-chip.archived { background: #e5e7eb; color: #4b5563; }
    [data-bs-theme="dark"] .ann-chip.draft { background: #2f343c; color: #d5d8dd; }
    [data-bs-theme="dark"] .ann-chip.scheduled { background: #1e3a5f; color: #bcd6ff; }
    [data-bs-theme="dark"] .ann-chip.published { background: #1d3322; color: #a5d6a7; }
    [data-bs-theme="dark"] .ann-chip.expired { background: #3a2f17; color: #f2c36b; }
    [data-bs-theme="dark"] .ann-chip.archived { background: #2a2d34; color: #c6c6c6; }
    .ann-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
    .ann-table td { vertical-align: middle; }
    .ann-title { font-weight: 700; color: var(--text-dark); overflow-wrap: anywhere; }
    .ann-muted { color: var(--text-light); font-size: .88rem; }
    .ann-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .ann-actions { display: inline-flex; gap: .35rem; justify-content: flex-end; flex-wrap: nowrap; }
    .ann-card .ann-actions { justify-content: flex-start; flex-wrap: wrap; }
    .ann-actions .btn { white-space: nowrap; }
    .ann-filters .btn-primary { background: #b3262f; border-color: #b3262f; color: #fff; }
    .ann-filters .btn-primary:hover { background: #8f1f26; border-color: #8f1f26; }
    [data-bs-theme="dark"] .ann-actions .btn-outline-primary { color: #ff9aa1; border-color: #ff9aa1; }
    [data-bs-theme="dark"] .ann-actions .btn-outline-secondary { color: #d5d8dd; border-color: #8b9098; }
    [data-bs-theme="dark"] .ann-actions .btn-outline-success { color: #8fd694; border-color: #8fd694; }
    [data-bs-theme="dark"] .ann-actions .btn:hover { background: #2f343c; }
    .ann-card { border: 1px solid var(--border-color); border-radius: 12px; background: var(--surface-color); padding: .9rem 1rem; margin-bottom: .75rem; }
    .ann-card dl { display: grid; grid-template-columns: 6.2rem 1fr; gap: .25rem .75rem; margin: .6rem 0; font-size: .9rem; }
    .ann-card dt { color: var(--text-light); font-weight: 600; }
    .ann-card dd { margin: 0; color: var(--text-dark); }
</style>
@endpush

@section('content')
@php
    $statusFilter = in_array($view, ['archived', 'all'], true) ? $view : 'active';
    $range = function ($a) {
        if ($a->published_at && $a->ends_at) {
            $sameYear = $a->published_at->year === $a->ends_at->year;
            return $a->published_at->format($sameYear ? 'M j' : 'M j, Y') . ' – ' . $a->ends_at->format('M j, Y');
        }
        if ($a->published_at) { return 'From ' . $a->published_at->format('M j, Y'); }
        if ($a->ends_at) { return 'Until ' . $a->ends_at->format('M j, Y'); }
        return '—';
    };
@endphp
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-bullhorn" title="Announcements & Updates" subtitle="Create, publish, and manage announcements sent to Guests, Managers, Receptionists, and the mobile app." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.announcements.index') }}" class="row g-3 align-items-end ann-filters">
            <div class="col-12 col-md-6 col-lg-6">
                <label class="form-label small text-muted" for="annSearch">Search</label>
                <input type="text" id="annSearch" name="search" class="form-control" placeholder="Search by title or message" value="{{ request('search') }}">
            </div>
            <div class="col-6 col-md-3 col-lg-2">
                <label class="form-label small text-muted" for="annStatus">Status</label>
                <select name="status" id="annStatus" class="form-select" onchange="this.form.submit()">
                    <option value="active" {{ $statusFilter === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="archived" {{ $statusFilter === 'archived' ? 'selected' : '' }}>Archived</option>
                    <option value="all" {{ $statusFilter === 'all' ? 'selected' : '' }}>All</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-lg-1">
                <button type="submit" class="btn btn-outline-secondary w-100" aria-label="Search"><i class="fas fa-search"></i></button>
            </div>
            <div class="col-12 col-lg-3 text-lg-end">
                <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary d-inline-flex align-items-center justify-content-center w-100"><i class="fas fa-plus me-1"></i> New announcement</a>
            </div>
        </form>
    </x-card>

    @if ($announcements->isEmpty())
        <x-card bodyClass="card-body">
            <x-empty-state icon="fas fa-bullhorn" message="{{ $statusFilter === 'archived' ? 'No archived announcements.' : 'No announcements found.' }}" />
            <p class="text-center"><a href="{{ route('admin.announcements.create') }}">Create one now</a></p>
        </x-card>
    @else
        <div class="text-muted small fw-semibold mb-2">{{ $announcements->total() }} {{ \Illuminate\Support\Str::plural('announcement', $announcements->total()) }}</div>

        {{-- desktop: a table --}}
        <div class="d-none d-md-block">
            <x-card bodyClass="table-responsive">
                <table class="table table-hover align-middle mb-0 ann-table">
                    <thead>
                        <tr><th>Title</th><th>Status</th><th>Audience</th><th>Publish &ndash; End</th><th>Last updated</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($announcements as $a)
                            @php $st = $a->displayStatus(); @endphp
                            <tr>
                                <td style="max-width: 360px;"><span class="ann-title">{{ $a->title }}</span></td>
                                <td><span class="ann-chip {{ strtolower($st) }}">{{ $st }}</span></td>
                                <td>{{ $a->audienceShort() }}</td>
                                <td class="ann-num">{{ $range($a) }}</td>
                                <td class="ann-num ann-muted">{{ \App\Support\HotelTime::format($a->updated_at, 'M j, Y g:i A') }}</td>
                                <td class="text-end">@include('admin.announcements.partials.actions', ['a' => $a])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>

        {{-- phones: one card per announcement --}}
        <div class="d-md-none">
            @foreach ($announcements as $a)
                @php $st = $a->displayStatus(); @endphp
                <div class="ann-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <span class="ann-title">{{ $a->title }}</span>
                        <span class="ann-chip {{ strtolower($st) }}">{{ $st }}</span>
                    </div>
                    <dl>
                        <dt>Audience</dt><dd>{{ $a->audienceShort() }}</dd>
                        <dt>Dates</dt><dd class="ann-num">{{ $range($a) }}</dd>
                        <dt>Updated</dt><dd class="ann-num">{{ \App\Support\HotelTime::format($a->updated_at, 'M j, Y g:i A') }}</dd>
                    </dl>
                    @include('admin.announcements.partials.actions', ['a' => $a])
                </div>
            @endforeach
        </div>
    @endif

    <div class="d-flex justify-content-center mt-4">
        <x-pagination :paginator="$announcements" />
    </div>
</div>

{{-- View: the full message, images and every detail live here, not in the list --}}
<div class="modal fade" id="annViewModal" tabindex="-1" aria-labelledby="annViewTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="annViewTitle"></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="d-flex flex-wrap gap-2 mb-3" id="annViewChips"></div>
                <div id="annViewImages" class="mb-3"></div>
                <p class="mb-3" id="annViewText" style="white-space: pre-line;"></p>
                <dl class="row small mb-0" id="annViewMeta"></dl>
            </div>
            <div class="modal-footer"><a href="#" class="btn btn-outline-secondary" id="annViewEdit"><i class="fas fa-pen"></i> Edit</a><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var modalEl = document.getElementById('annViewModal'), esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };
    document.querySelectorAll('[data-ann-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var a = JSON.parse(btn.getAttribute('data-ann-view'));
            document.getElementById('annViewTitle').textContent = a.title;
            document.getElementById('annViewChips').innerHTML = '<span class="ann-chip ' + esc(a.status.toLowerCase()) + '">' + esc(a.status) + '</span><span class="badge text-bg-secondary align-self-center">' + esc(a.audience) + '</span>';
            document.getElementById('annViewImages').innerHTML = (a.images || []).map(function (u) { return '<img src="' + esc(u) + '" alt="" class="img-fluid rounded mb-2 d-block">'; }).join('');
            document.getElementById('annViewText').textContent = a.content;
            document.getElementById('annViewMeta').innerHTML = [['Publish date', a.published], ['End date', a.ends], ['Notifications sent', a.notified], ['Last edited', a.edited], ['Last updated', a.updated]].map(function (r) { return '<dt class="col-sm-4">' + r[0] + '</dt><dd class="col-sm-8">' + esc(r[1]) + '</dd>'; }).join('');
            document.getElementById('annViewEdit').href = a.edit;
            bootstrap.Modal.getOrCreateInstance(modalEl).show();
        });
    });
})();
</script>
@endpush
