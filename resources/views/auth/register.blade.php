@extends('layouts.guest')

@section('title', 'Register - Velocity Suites')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/portal.css') }}?v={{ filemtime(public_path('css/portal.css')) }}">
@endpush

@section('content')
<div class="auth-card vs-reg" id="vsRegRoot" style="max-width:600px">
    <a href="{{ url('/') }}" class="vs-back" data-topback data-custom><i class="fas fa-arrow-left" aria-hidden="true"></i> Back</a>
    <div id="vsRegWrap">
        <div class="logo vs-reg-logo">
            <span class="logo-badge" style="width: 84px; height: 72px;"><img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites"></span>
            <p class="fw-bold text-brand text-uppercase mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.35rem;">Velocity Suites</p>
            <p class="text-muted mb-1">Your comfort is our service</p>
            <p class="fw-bold mb-0" style="color: #000; font-size: 1.15rem;">Create your account</p>
        </div>
        <div class="vs-stepline" id="vsRegLine" aria-live="polite"></div>
        <div class="progress vs-prog mb-3" id="vsRegBar" role="progressbar" aria-label="Sign-up progress" aria-valuemin="0" aria-valuemax="6" aria-valuenow="1"><div class="progress-bar"></div></div>
        <div id="vsRegAlert" aria-live="polite"></div>
        <form id="vsReg" novalidate autocomplete="on">
                    <section data-step>
                        <div class="form-group"><label for="first_name">First name</label><input class="form-control" id="first_name" name="first_name" autocomplete="given-name" maxlength="100"></div>
                        <div class="form-group"><label for="middle_name">Middle name <span class="text-muted fw-normal">(optional)</span></label><input class="form-control" id="middle_name" name="middle_name" autocomplete="additional-name" maxlength="100"></div>
                        <div class="form-group"><label for="last_name">Last name</label><input class="form-control" id="last_name" name="last_name" autocomplete="family-name" maxlength="100"></div>
                    </section>

                    <section data-step class="d-none">
                        <div class="form-group">
                            <label id="vsGenderLabel">Gender</label>
                            <div id="vsGender" class="d-flex gap-3" role="radiogroup" aria-labelledby="vsGenderLabel">
                                <div class="form-check"><input class="form-check-input" type="radio" name="gender" id="gm" value="male"><label class="form-check-label fw-normal" for="gm">Male</label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="gender" id="gf" value="female"><label class="form-check-label fw-normal" for="gf">Female</label></div>
                            </div>
                        </div>
                        <div class="form-group"><label for="date_of_birth">Date of birth</label><input class="form-control" type="date" id="date_of_birth" name="date_of_birth" autocomplete="bday"><div class="field-caption small mt-1" id="vsAge">You must be 18 or older.</div></div>
                    </section>

                    <section data-step class="d-none">
                        <div class="form-group"><label for="email">Email address</label><input class="form-control" type="email" id="email" name="email" autocomplete="email" inputmode="email" maxlength="255"><div class="field-caption small mt-1">We send your verification code here.</div></div>
                        <div class="form-group"><label for="mobile_number">Mobile number</label><input class="form-control" type="tel" id="mobile_number" name="mobile_number" autocomplete="tel" placeholder="09171234567"><div class="field-caption small mt-1">11 digits, starting with 09.</div></div>
                    </section>

                    <section data-step class="d-none">
                        @include('portal.partials.address')
                    </section>

                    <section data-step class="d-none">
                        <div class="form-group">
                            <label for="password">Password</label>
                            <div class="input-group"><input class="form-control" type="password" id="password" name="password" autocomplete="new-password"><button type="button" class="btn btn-outline-secondary" data-eye="password" aria-label="Show password"><i class="fas fa-eye"></i></button></div>
                            <div id="vsRegCheck" class="small mt-2"></div>
                        </div>
                        <div class="form-group"><label for="password_confirmation">Confirm password</label><input class="form-control" type="password" id="password_confirmation" name="password_confirmation" autocomplete="new-password"></div>
                        <div class="form-group">
                            <label for="vsPhoto">Profile photo <span class="text-muted fw-normal">(optional)</span></label>
                            <input class="form-control" type="file" id="vsPhoto" accept="image/*" capture="user">
                            <img id="vsPhotoPrev" class="d-none mt-2 rounded-circle" alt="Your profile photo preview" style="width:84px;height:84px;object-fit:cover">
                        </div>
                    </section>

                    <section data-step class="d-none">
                        <div id="vsRegReview" class="mb-3"></div>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" id="terms" name="terms">
                            <label class="form-check-label fw-normal" for="terms">I have read and agree to the <a href="#" id="vsTermsLink">Terms and agreement</a>.</label>
                        </div>
                    </section>
                <div class="vs-reg-actions"><button type="button" class="btn btn-light" data-back>Back</button><button type="button" class="btn btn-velocity" data-next>Continue</button><button type="button" class="btn btn-velocity d-none" id="vsRegSubmit" data-busy-text="Sending code">Create account</button></div>
                </form>
                <p class="text-center mt-3 mb-0">Already have an account? <a href="{{ route('login') }}" class="fw-semibold">Sign in</a></p>
            </div>
    <div id="vsRegCode" class="d-none"></div>
    <div id="vsTermsText" class="d-none">@include('portal.partials.terms-agreement')</div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/address-cascade.js') }}?v={{ filemtime(public_path('js/address-cascade.js')) }}"></script>
<script src="{{ asset('js/portal/back-link.js') }}?v={{ filemtime(public_path('js/portal/back-link.js')) }}"></script>
<script src="{{ asset('js/portal/core.js') }}?v={{ filemtime(public_path('js/portal/core.js')) }}"></script>
<script src="{{ asset('js/portal/auth.js') }}?v={{ filemtime(public_path('js/portal/auth.js')) }}"></script>
<script>VSAuth.register(document.getElementById('vsRegRoot'));</script>
@endpush
