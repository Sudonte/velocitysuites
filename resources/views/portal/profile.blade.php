@extends('layouts.portal')
@section('title', 'Profile - Velocity Suites')
@section('content')
<div class="container pb-5" style="max-width:760px"><div class="pt-3" id="vsProfile" aria-live="polite"></div></div>
@endsection
@push('scripts')
<script src="{{ asset('js/portal/stays.js') }}?v={{ filemtime(public_path('js/portal/stays.js')) }}"></script>
<script src="{{ asset('js/portal/wizard.js') }}?v={{ filemtime(public_path('js/portal/wizard.js')) }}"></script>
<script src="{{ asset('js/portal/account.js') }}?v={{ filemtime(public_path('js/portal/account.js')) }}"></script>
<script>VSAccount.profile(document.querySelector('main'));</script>
@endpush
