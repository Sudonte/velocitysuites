@extends('layouts.guest')

@section('title', 'Register - Velocity Suites')

@push('styles')
<style>
    /* Landscape-style layout: much wider than the standard single-column
       auth card so the wizard has room to breathe on desktop/laptop
       screens. Collapses back to a single column automatically below the
       lg breakpoint, so nothing breaks or overflows on tablet/mobile. */
    .register-card {
        max-width: 1040px;
    }
    .form-section-title {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        font-weight: 700;
        color: var(--primary-color);
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.04em;
        margin-bottom: 0.9rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--border-color);
    }
    /* Icon badge in front of each section title - matches the icon+title+
       divider pattern from the Velocity Suites mobile app's registration
       screen (see app/src/main/res/layout/registration.xml), adapted to
       this design system's red/white palette instead of the app's own. */
    .form-section-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        background-color: rgba(193, 18, 31, 0.1);
        color: var(--primary-color);
        font-size: 0.8rem;
        flex-shrink: 0;
    }
    .form-section + .form-section {
        margin-top: 1.75rem;
    }
    .field-caption {
        display: block;
        margin-top: 0.35rem;
        font-size: 0.78rem;
        color: var(--text-light);
    }

    /* --- Wizard step visibility --- */
    .wizard-step { display: none; }
    .wizard-step.active { display: block; }
    .wizard-step-desc {
        color: var(--text-light);
        font-size: 0.92rem;
        margin-bottom: 1.25rem;
    }

    /* --- Desktop stepper --- */
    .wizard-stepper-desktop {
        display: flex;
        align-items: flex-start;
        margin-bottom: 2rem;
    }
    .wizard-stepper-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        flex: 1;
        position: relative;
    }
    .wizard-stepper-circle {
        width: 34px;
        height: 34px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: 700;
        font-size: 0.9rem;
        background: #f1f1f1;
        color: var(--text-light);
        border: 2px solid #e2e2e2;
        margin-bottom: 0.4rem;
        transition: all 0.2s ease;
    }
    .wizard-stepper-label {
        font-size: 0.78rem;
        font-weight: 600;
        color: var(--text-light);
    }
    .wizard-stepper-item.active .wizard-stepper-circle {
        background: var(--primary-color);
        border-color: var(--primary-color);
        color: #fff;
    }
    .wizard-stepper-item.active .wizard-stepper-label { color: var(--primary-color); }
    .wizard-stepper-item.completed .wizard-stepper-circle {
        background: var(--primary-color);
        border-color: var(--primary-color);
        color: #fff;
    }
    .wizard-stepper-item.completed .wizard-stepper-label { color: var(--primary-color); }
    .wizard-stepper-connector {
        flex: 1;
        height: 2px;
        background: #e2e2e2;
        margin-top: 17px;
    }
    .wizard-stepper-item.completed + .wizard-stepper-connector { background: var(--primary-color); }

    /* --- Mobile stepper --- */
    .wizard-stepper-mobile { display: none; margin-bottom: 1.5rem; }
    .wizard-stepper-mobile-label {
        font-weight: 700;
        font-size: 0.85rem;
        color: var(--primary-color);
        text-transform: uppercase;
        letter-spacing: 0.03em;
        margin-bottom: 0.5rem;
    }
    .wizard-stepper-mobile-track {
        height: 6px;
        border-radius: 3px;
        background: #e2e2e2;
        overflow: hidden;
    }
    .wizard-stepper-mobile-fill {
        height: 100%;
        background: var(--primary-color);
        width: 20%;
        transition: width 0.25s ease;
    }

    @media (max-width: 767px) {
        .wizard-stepper-desktop { display: none; }
        .wizard-stepper-mobile { display: block; }
    }

    .wizard-nav {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 1.75rem;
        gap: 0.75rem;
    }
    .wizard-nav .btn { min-height: 44px; }
    .wizard-nav-spacer { flex: 1; }

    /* --- Password checklist --- */
    .password-checklist {
        list-style: none;
        padding: 0;
        margin: 0.6rem 0 0;
        font-size: 0.8rem;
    }
    .password-checklist li {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        color: var(--text-light);
        margin-bottom: 0.25rem;
    }
    .password-checklist li .req-icon::before { content: "\f111"; font-family: "Font Awesome 5 Free"; font-weight: 900; font-size: 0.55rem; }
    .password-checklist li.met { color: #1f8a3b; }
    .password-checklist li.met .req-icon::before { content: "\f058"; font-size: 0.85rem; }
    .password-strength-track {
        height: 5px;
        border-radius: 3px;
        background: #e2e2e2;
        overflow: hidden;
        margin-top: 0.75rem;
    }
    .password-strength-fill { height: 100%; width: 0; background: #dc3545; transition: width 0.2s ease, background-color 0.2s ease; }
    .password-strength-text { font-size: 0.78rem; font-weight: 600; margin-top: 0.3rem; color: var(--text-light); }

    /* --- Review step --- */
    .review-card {
        border: 1px solid var(--border-color);
        border-radius: 10px;
        padding: 1rem 1.25rem;
        margin-bottom: 1rem;
    }
    .review-card-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-weight: 700;
        text-transform: uppercase;
        font-size: 0.8rem;
        letter-spacing: 0.03em;
        color: var(--primary-color);
        margin-bottom: 0.75rem;
    }
    .review-row { display: flex; flex-wrap: wrap; gap: 0.25rem 0.5rem; font-size: 0.9rem; margin-bottom: 0.4rem; }
    .review-row:last-child { margin-bottom: 0; }
    .review-label { color: var(--text-light); min-width: 140px; }
    .review-value { font-weight: 600; }
</style>
@endpush

@section('content')
<div class="auth-card register-card">
    <div class="logo">
        <span class="logo-badge" style="width: 84px; height: 72px;">
            <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites">
        </span>
        <p class="fw-bold text-brand text-uppercase mt-2 mb-0" style="letter-spacing: 1px; font-size: 1.35rem;">Velocity Suites</p>
        <p class="fw-bold mb-0" style="color: #000; font-size: 1.15rem;">Create Your Account</p>
        <p class="field-caption text-center mt-1 mb-0">Complete the steps below to create your Velocity Suites account.</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Please review the highlighted fields.</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Desktop stepper -->
    <div class="wizard-stepper-desktop" id="wizardStepperDesktop">
        <div class="wizard-stepper-item" data-step="1">
            <span class="wizard-stepper-circle">1</span>
            <span class="wizard-stepper-label">Personal</span>
        </div>
        <div class="wizard-stepper-connector"></div>
        <div class="wizard-stepper-item" data-step="2">
            <span class="wizard-stepper-circle">2</span>
            <span class="wizard-stepper-label">Contact</span>
        </div>
        <div class="wizard-stepper-connector"></div>
        <div class="wizard-stepper-item" data-step="3">
            <span class="wizard-stepper-circle">3</span>
            <span class="wizard-stepper-label">Security</span>
        </div>
        <div class="wizard-stepper-connector"></div>
        <div class="wizard-stepper-item" data-step="4">
            <span class="wizard-stepper-circle">4</span>
            <span class="wizard-stepper-label">Profile</span>
        </div>
        <div class="wizard-stepper-connector"></div>
        <div class="wizard-stepper-item" data-step="5">
            <span class="wizard-stepper-circle">5</span>
            <span class="wizard-stepper-label">Review</span>
        </div>
    </div>

    <!-- Mobile stepper -->
    <div class="wizard-stepper-mobile" id="wizardStepperMobile">
        <div class="wizard-stepper-mobile-label" id="wizardStepperMobileLabel">Step 1 of 5 &mdash; Personal Information</div>
        <div class="wizard-stepper-mobile-track"><div class="wizard-stepper-mobile-fill" id="wizardStepperMobileFill"></div></div>
    </div>

    <form method="POST" action="{{ route('register.post') }}" enctype="multipart/form-data" id="registerForm" novalidate>
        @csrf

        {{-- STEP 1: Personal Information --}}
        <div class="wizard-step" data-step="1">
            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-id-card"></i></span> Personal Information</div>
                <p class="wizard-step-desc">Tell us a little about yourself.</p>

                <div class="row g-2">
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="first_name">First Name *</label>
                            <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                                   id="first_name" name="first_name" value="{{ old('first_name') }}" required>
                            @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="middle_name">Middle Name</label>
                            <input type="text" class="form-control @error('middle_name') is-invalid @enderror"
                                   id="middle_name" name="middle_name" value="{{ old('middle_name') }}">
                            <small class="field-caption">Optional</small>
                            @error('middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-sm-4">
                        <div class="form-group">
                            <label for="last_name">Last Name *</label>
                            <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                                   id="last_name" name="last_name" value="{{ old('last_name') }}" required>
                            @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="date_of_birth">Date of Birth *</label>
                            <input type="date" class="form-control @error('date_of_birth') is-invalid @enderror"
                                   id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}"
                                   max="{{ now()->toDateString() }}" required>
                            <small class="field-caption">You must be at least 18 years old to register.</small>
                            @error('date_of_birth')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="age">Age</label>
                            <input type="number" class="form-control @error('age') is-invalid @enderror"
                                   id="age" name="age" value="{{ old('age') }}" readonly required min="18">
                            <small class="field-caption">Calculated automatically from your date of birth.</small>
                            @error('age')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="gender">Gender *</label>
                            <select class="form-control @error('gender') is-invalid @enderror"
                                    id="gender" name="gender" required>
                                <option value="">Select Gender</option>
                                <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Male</option>
                                <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Female</option>
                            </select>
                            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="wizard-nav">
                <span class="wizard-nav-spacer"></span>
                <button type="button" class="btn btn-primary" data-wizard-next>Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        {{-- STEP 2: Contact & Address --}}
        <div class="wizard-step" data-step="2">
            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-envelope"></i></span> Contact Information</div>
                <p class="wizard-step-desc">How we'll reach you and verify your account.</p>

                <div class="form-group">
                    <label for="email">Email Address *</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email') }}" required>
                    </div>
                    <small class="field-caption">Please use a valid Google/Gmail address - it's needed to verify your account.</small>
                    @error('email')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="form-group mb-0">
                    <label for="mobile_number">Mobile Number *</label>
                    <input type="text" class="form-control @error('mobile_number') is-invalid @enderror"
                           id="mobile_number" name="mobile_number" value="{{ old('mobile_number') }}" placeholder="09XXXXXXXXX" required>
                    <small class="field-caption" id="mobileFormatHint">09XXXXXXXXX or +639XXXXXXXXX.</small>
                    @error('mobile_number')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-map-marker-alt"></i></span> Address Information</div>
                <p class="wizard-step-desc">Where you're currently located.</p>

                <div data-address-cascade
                     data-initial="{{ json_encode(['country' => old('country', 'Philippines'), 'region' => old('region'), 'province' => old('province'), 'city' => old('city'), 'barangay' => old('barangay'), 'timezone' => old('timezone')]) }}">
                    <div class="row">
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="addrCountry">Country *</label>
                                <select class="form-control @error('country') is-invalid @enderror"
                                        id="addrCountry" name="country" data-address-field="country" required></select>
                                <div class="invalid-feedback d-block" id="addrCountry-error" data-address-error="country" role="alert"></div>
                                @error('country')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-region-group>
                            <div class="form-group">
                                <label for="addrRegion">Region *</label>
                                <select class="form-control @error('region') is-invalid @enderror"
                                        id="addrRegion" name="region" data-address-field="region" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrRegionText" name="region"
                                       data-address-field="region" data-role="text" placeholder="Region" disabled>
                                <small class="field-caption" data-address-loading="region" hidden><i class="fas fa-spinner fa-spin"></i> Loading regions&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrRegion-error" data-address-error="region" role="alert"></div>
                            </div>
                        </div>
                    </div>

                    <!-- Shown only for non-Philippine countries: no further address detail is
                         collected outside the Philippines. -->
                    <p class="field-caption" data-address-intl-note hidden>Address details are only collected for Philippine addresses.</p>

                    <!-- Timezone: only shown for countries spanning multiple timezones (United
                         States, Canada, Australia); single-timezone countries are resolved
                         server-side from Country alone. -->
                    <div class="row" data-address-timezone-group hidden>
                        <div class="col-sm-6">
                            <div class="form-group">
                                <label for="addrTimezone">Timezone *</label>
                                <select class="form-control @error('timezone') is-invalid @enderror"
                                        id="addrTimezone" name="timezone" data-address-field="timezone"></select>
                                <div class="invalid-feedback d-block" id="addrTimezone-error" data-address-error="timezone" role="alert"></div>
                                @error('timezone')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6" data-address-province-group>
                            <div class="form-group">
                                <label for="addrProvince">Province *</label>
                                <select class="form-control @error('province') is-invalid @enderror"
                                        id="addrProvince" name="province" data-address-field="province" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrProvinceText" name="province"
                                       data-address-field="province" data-role="text" placeholder="Province" disabled>
                                <small class="field-caption" data-address-loading="province" hidden><i class="fas fa-spinner fa-spin"></i> Loading provinces&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrProvince-error" data-address-error="province" role="alert"></div>
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-city-group>
                            <div class="form-group">
                                <label for="addrCity">City / Municipality *</label>
                                <select class="form-control @error('city') is-invalid @enderror"
                                        id="addrCity" name="city" data-address-field="city" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrCityText" name="city"
                                       data-address-field="city" data-role="text" placeholder="City / Municipality" disabled>
                                <small class="field-caption" data-address-loading="city" hidden><i class="fas fa-spinner fa-spin"></i> Loading cities&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrCity-error" data-address-error="city" role="alert"></div>
                            </div>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-sm-6" data-address-barangay-group>
                            <div class="form-group">
                                <label for="addrBarangay">Barangay *</label>
                                <select class="form-control @error('barangay') is-invalid @enderror"
                                        id="addrBarangay" name="barangay" data-address-field="barangay" disabled></select>
                                <small class="field-caption" data-address-loading="barangay" hidden><i class="fas fa-spinner fa-spin"></i> Loading barangays&hellip;</small>
                                <div class="invalid-feedback d-block" id="addrBarangay-error" data-address-error="barangay" role="alert"></div>
                            </div>
                        </div>
                        <div class="col-sm-6" data-address-zip-group>
                            <div class="form-group">
                                <label for="addrZip">ZIP Code</label>
                                <input type="text" class="form-control @error('zip_code') is-invalid @enderror"
                                       id="addrZip" name="zip_code" data-address-field="zip_code" value="{{ old('zip_code') }}">
                                @error('zip_code')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>

                    <div data-address-street-group>
                        <div class="form-group mb-0">
                            <label for="addrStreet">Street / House No. / Subdivision *</label>
                            <input type="text" class="form-control @error('street') is-invalid @enderror"
                                   id="addrStreet" name="street" data-address-field="street" value="{{ old('street') }}" required>
                            <div class="invalid-feedback d-block" id="addrStreet-error" data-address-error="street" role="alert"></div>
                            @error('street')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn btn-outline-secondary" data-wizard-back><i class="fas fa-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" data-wizard-next>Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        {{-- STEP 3: Account Security --}}
        <div class="wizard-step" data-step="3">
            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-lock"></i></span> Account Security</div>
                <p class="wizard-step-desc">Create a strong password to protect your account.</p>

                <div class="row">
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="password">Password *</label>
                            <div class="password-input-wrapper">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                                           id="password" name="password" minlength="8" required>
                                </div>
                                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                            </div>
                            @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="form-group">
                            <label for="password_confirmation">Confirm Password *</label>
                            <div class="password-input-wrapper">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                                    <input type="password" class="form-control"
                                           id="password_confirmation" name="password_confirmation" minlength="8" required>
                                </div>
                                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                            </div>
                            <small class="field-caption" id="passwordMatchHint">Must match the password above.</small>
                        </div>
                    </div>
                </div>

                <ul class="password-checklist" id="passwordChecklist">
                    <li data-req="length"><span class="req-icon"></span> At least 8 characters</li>
                    <li data-req="upper"><span class="req-icon"></span> One uppercase letter</li>
                    <li data-req="lower"><span class="req-icon"></span> One lowercase letter</li>
                    <li data-req="number"><span class="req-icon"></span> One number</li>
                </ul>
                <div class="password-strength-track"><div class="password-strength-fill" id="passwordStrengthFill"></div></div>
                <div class="password-strength-text" id="passwordStrengthText"></div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn btn-outline-secondary" data-wizard-back><i class="fas fa-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" data-wizard-next>Continue <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        {{-- STEP 4: Profile --}}
        <div class="wizard-step" data-step="4">
            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-user-circle"></i></span> Profile</div>
                <p class="wizard-step-desc">Add a profile picture to personalize your account.</p>

                <div class="form-group mb-0 text-center">
                    <div class="d-flex justify-content-center mb-3">
                        <img id="profilePicturePreview" alt="Profile picture preview"
                             class="rounded-circle border" style="width:96px;height:96px;object-fit:cover;display:none;"
                             src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7">
                        <div id="profilePicturePlaceholder"
                             class="rounded-circle bg-light border d-flex align-items-center justify-content-center"
                             style="width:96px;height:96px;">
                            <i class="fas fa-user text-muted" style="font-size:36px;"></i>
                        </div>
                    </div>
                    <p class="fw-bold mb-1">Upload Profile Picture</p>
                    <p class="field-caption mb-3">JPG, JPEG or PNG &bull; Maximum 5 MB &bull; Optional</p>

                    <input type="file" name="profile_picture" id="profilePictureInput" accept="image/jpeg,image/png,image/jpg"
                           class="d-none @error('profile_picture') is-invalid @enderror">

                    <div id="profilePictureActions">
                        <button type="button" class="btn btn-outline-secondary" id="btnChooseImage"><i class="fas fa-image"></i> Choose Image</button>
                    </div>
                    <div id="profilePictureSelectedActions" class="d-none">
                        <span class="field-caption d-block mb-2" id="profilePictureFileName"></span>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnReplaceImage">Replace Image</button>
                        <button type="button" class="btn btn-outline-danger btn-sm ms-1" id="btnRemoveImage">Remove Image</button>
                    </div>
                    @error('profile_picture')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn btn-outline-secondary" data-wizard-back><i class="fas fa-arrow-left"></i> Back</button>
                <button type="button" class="btn btn-primary" data-wizard-next>Review Account <i class="fas fa-arrow-right"></i></button>
            </div>
        </div>

        {{-- STEP 5: Review & Create Account --}}
        <div class="wizard-step" data-step="5">
            <div class="form-section">
                <div class="form-section-title"><span class="form-section-icon"><i class="fas fa-clipboard-check"></i></span> Review Your Information</div>
                <p class="wizard-step-desc">Please review your details before creating your account.</p>

                <div class="review-card">
                    <div class="review-card-header">
                        <span>Personal Information</span>
                        <button type="button" class="btn btn-link btn-sm p-0" data-wizard-edit="1">Edit</button>
                    </div>
                    <div class="review-row"><span class="review-label">Full Name</span><span class="review-value" id="reviewFullName"></span></div>
                    <div class="review-row"><span class="review-label">Date of Birth</span><span class="review-value" id="reviewDob"></span></div>
                    <div class="review-row"><span class="review-label">Age</span><span class="review-value" id="reviewAge"></span></div>
                    <div class="review-row"><span class="review-label">Gender</span><span class="review-value" id="reviewGender"></span></div>
                    <div class="review-row"><span class="review-label">Mobile Number</span><span class="review-value" id="reviewMobile"></span></div>
                </div>

                <div class="review-card">
                    <div class="review-card-header">
                        <span>Contact Information</span>
                        <button type="button" class="btn btn-link btn-sm p-0" data-wizard-edit="2">Edit</button>
                    </div>
                    <div class="review-row"><span class="review-label">Email</span><span class="review-value" id="reviewEmail"></span></div>
                </div>

                <div class="review-card">
                    <div class="review-card-header">
                        <span>Address</span>
                        <button type="button" class="btn btn-link btn-sm p-0" data-wizard-edit="2">Edit</button>
                    </div>
                    <div class="review-row"><span class="review-label">Country</span><span class="review-value" id="reviewCountry"></span></div>
                    <div class="review-row"><span class="review-label">Region</span><span class="review-value" id="reviewRegion"></span></div>
                    <div class="review-row"><span class="review-label">Province</span><span class="review-value" id="reviewProvince"></span></div>
                    <div class="review-row"><span class="review-label">City / Municipality</span><span class="review-value" id="reviewCity"></span></div>
                    <div class="review-row"><span class="review-label">Barangay</span><span class="review-value" id="reviewBarangay"></span></div>
                    <div class="review-row"><span class="review-label">ZIP Code</span><span class="review-value" id="reviewZip"></span></div>
                    <div class="review-row"><span class="review-label">Street Address</span><span class="review-value" id="reviewStreet"></span></div>
                    <div class="review-row"><span class="review-label">Timezone</span><span class="review-value" id="reviewTimezone"></span></div>
                </div>

                <div class="review-card">
                    <div class="review-card-header">
                        <span>Account Security</span>
                        <button type="button" class="btn btn-link btn-sm p-0" data-wizard-edit="3">Edit</button>
                    </div>
                    <div class="review-row"><span class="review-label">Password</span><span class="review-value">Configured</span></div>
                </div>

                <div class="review-card">
                    <div class="review-card-header">
                        <span>Profile</span>
                        <button type="button" class="btn btn-link btn-sm p-0" data-wizard-edit="4">Edit</button>
                    </div>
                    <div class="review-row"><span class="review-label">Profile Picture</span><span class="review-value" id="reviewProfilePicture">No profile picture selected</span></div>
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn btn-outline-secondary" data-wizard-back><i class="fas fa-arrow-left"></i> Back</button>
                <button type="submit" class="btn btn-primary" id="registerSubmitBtn">
                    <i class="fas fa-user-plus"></i> <span class="btn-label">Create Account</span>
                </button>
            </div>
        </div>
    </form>

    <hr>

    <div class="text-center">
        <p class="mb-0">Already have an account? <a href="{{ route('login') }}" class="fw-bold">Login here</a></p>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/address-cascade.js') }}?v={{ filemtime(public_path('js/address-cascade.js')) }}"></script>
<script src="{{ asset('js/register-wizard.js') }}?v={{ filemtime(public_path('js/register-wizard.js')) }}"></script>
<script>
(function () {
    const form = document.getElementById('registerForm');
    const btn = document.getElementById('registerSubmitBtn');
    if (!form || !btn) return;
    form.addEventListener('submit', function () {
        if (!form.checkValidity()) return;
        btn.disabled = true;
        btn.querySelector('.btn-label').textContent = 'Creating Account...';
        btn.insertAdjacentHTML('afterbegin', '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>');
    });
})();
</script>
@endpush
