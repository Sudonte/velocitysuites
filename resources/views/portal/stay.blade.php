@extends('layouts.portal')
@section('title', ucfirst($kind) . ' #' . $id . ' - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:860px">
    <nav class="vs-head" aria-label="Breadcrumb"><a href="{{ route('guest.reservations.index') }}" class="text-decoration-none"><i class="fas fa-arrow-left me-1"></i>Booking &amp; Reservation</a></nav>
    <div id="vsDetails" aria-live="polite"></div>
</div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/amenities.js') }}?v={{ filemtime(public_path('js/portal/amenities.js')) }}"></script>
<script>VSStay.details(document.querySelector('main'), '{{ $kind }}', {{ $id }});</script>
@endpush
