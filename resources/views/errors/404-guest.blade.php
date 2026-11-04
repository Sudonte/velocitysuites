@extends('layouts.portal')
@section('title', "We couldn't find that page - Velocity Suites")
@section('content')
<div class="container pb-5" style="max-width:640px">
    <div class="vs-empty mt-5" role="alert">
        <i class="fas fa-circle-question" aria-hidden="true"></i>
        <h1 class="h4 fw-bold">We couldn&rsquo;t find that page</h1>
        <p class="text-muted">It may have been removed, belong to another account, or the link is not right.</p>
        <div class="d-flex flex-wrap justify-content-center gap-2">
            <a class="btn btn-velocity" href="{{ route('guest.dashboard') }}">Dashboard</a>
            <a class="btn btn-outline-velocity" href="{{ url('/guest/reservations') }}">Booking &amp; Reservation</a>
        </div>
    </div>
</div>
@endsection
