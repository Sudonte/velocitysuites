@extends('layouts.app')

@section('title', 'Profile - Guest')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user-circle" title="My Profile" subtitle="Manage your account information, profile picture, and password." />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <div class="row">
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <x-user-avatar :user="$user" :size="120" class="mb-3" />
                    <h4 class="mt-3 mb-1">{{ $user->full_name }}</h4>
                    <p class="text-muted mb-2">{{ $user->email }}</p>
                    <span class="badge badge-brand">{{ $user->role_label }}</span>
                    <hr>
                    <p class="mb-0 text-muted small">
                        <i class="fas fa-calendar"></i> Joined {{ $user->created_at->format('M d, Y') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <!-- Profile Picture -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-camera"></i> Profile Picture</h5>
                </div>
                <div class="card-body">
                    <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                        <img id="profilePicturePreview" alt="Profile picture"
                             class="rounded-circle border"
                             style="width:96px;height:96px;object-fit:cover;{{ $user->profile_picture_url ? '' : ' display:none;' }}"
                             src="{{ $user->profile_picture_url ?? 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' }}">
                        <div id="profilePicturePlaceholder"
                             class="user-avatar-initials"
                             style="width:96px;height:96px;font-size:38px;{{ $user->profile_picture_url ? ' display:none;' : '' }}">
                            {{ $user->initials }}
                        </div>
                        <p class="text-muted small mb-0">JPG, JPEG, or PNG. Max size 5MB.</p>
                    </div>

                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <form action="{{ route('guest.profile.picture.update') }}" method="POST" enctype="multipart/form-data" class="d-flex flex-wrap align-items-start gap-2">
                            @csrf
                            <div class="flex-grow-1" style="min-width:220px;">
                                <input type="file" name="profile_picture" id="profilePictureInput" accept="image/jpeg,image/png,image/jpg"
                                       class="form-control @error('profile_picture') is-invalid @enderror" required>
                                @error('profile_picture')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-upload"></i> Upload
                            </button>
                        </form>

                        @if($guest && $guest->profile_picture)
                            <form action="{{ route('guest.profile.picture.remove') }}" method="POST" onsubmit="return confirm('Remove your profile picture?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>

            <form action="{{ route('guest.profile.update') }}" method="POST">
                @csrf
                @method('PUT')

                <x-card title="Personal Information" icon="fas fa-id-card" bodyClass="card-body" class="mb-4">
                    <div class="row">
                        <div class="col-md-5 mb-3">
                            <label class="form-label">First Name</label>
                            <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $user->first_name) }}" required>
                            @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control @error('middle_name') is-invalid @enderror" value="{{ old('middle_name', $user->middle_name) }}">
                            @error('middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="form-label">Last Name</label>
                            <input type="text" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $user->last_name) }}" required>
                            @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-control @error('gender') is-invalid @enderror">
                                <option value="">Select Gender</option>
                                <option value="male" {{ old('gender', $guest->gender ?? '') == 'male' ? 'selected' : '' }}>Male</option>
                                <option value="female" {{ old('gender', $guest->gender ?? '') == 'female' ? 'selected' : '' }}>Female</option>
                            </select>
                            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" id="guestDob"
                                   class="form-control @error('date_of_birth') is-invalid @enderror"
                                   value="{{ old('date_of_birth', $guest && $guest->date_of_birth ? $guest->date_of_birth->format('Y-m-d') : '') }}"
                                   max="{{ now()->toDateString() }}">
                            @error('date_of_birth')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Age</label>
                            <input type="number" id="guestAge" class="form-control" value="{{ $guest->age ?? '' }}" readonly>
                            <small class="text-muted">Calculated automatically from your date of birth.</small>
                        </div>
                    </div>
                </x-card>

                <x-card title="Contact Information" icon="fas fa-envelope" bodyClass="card-body" class="mb-4">
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror" value="{{ old('email', $user->email) }}" required>
                        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Mobile Number</label>
                        <input type="text" name="mobile_number" id="guestMobile" class="form-control @error('mobile_number') is-invalid @enderror" value="{{ old('mobile_number', $guest->mobile_number ?? '') }}" placeholder="09XXXXXXXXX">
                        <small class="text-muted" id="guestMobileHint">09XXXXXXXXX or +639XXXXXXXXX.</small>
                        @error('mobile_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </x-card>

                <x-card title="Address" icon="fas fa-map-marker-alt" bodyClass="card-body" class="mb-4">
                    <small class="text-muted d-block mb-3">Select your location step by step - Region, Province, City, and Barangay load automatically based on your previous selection.</small>

                    <div data-address-cascade data-address-required="false" data-initial="{{ json_encode([
                        'country' => old('country', $guest->country ?? 'Philippines'),
                        'region' => old('region', $guest->region ?? null),
                        'province' => old('province', $guest->province ?? null),
                        'city' => old('city', $guest->city ?? null),
                        'barangay' => old('barangay', $guest->barangay ?? null),
                        'timezone' => old('timezone', $guest->timezone ?? null),
                    ]) }}">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="addrCountry">Country</label>
                                <select class="form-control @error('country') is-invalid @enderror"
                                        id="addrCountry" name="country" data-address-field="country" required></select>
                                <div class="invalid-feedback d-block" data-address-error="country" role="alert"></div>
                            </div>
                            <div class="col-md-6 mb-3" data-address-province-group>
                                <label class="form-label" for="addrProvince">Province</label>
                                <select class="form-control @error('province') is-invalid @enderror"
                                        id="addrProvince" name="province" data-address-field="province" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrProvinceText" name="province"
                                       data-address-field="province" data-role="text" placeholder="Province" disabled>
                                <small class="text-muted" data-address-loading="province" hidden><i class="fas fa-spinner fa-spin"></i> Loading provinces&hellip;</small>
                                <div class="invalid-feedback d-block" data-address-error="province" role="alert"></div>
                            </div>
                        </div>

                        <small class="text-muted d-block mb-3" data-address-intl-note hidden>Address details are only collected for Philippine addresses.</small>

                        <div class="row" data-address-timezone-group hidden>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="addrTimezone">Timezone</label>
                                <select class="form-control @error('timezone') is-invalid @enderror"
                                        id="addrTimezone" name="timezone" data-address-field="timezone"></select>
                                <div class="invalid-feedback d-block" data-address-error="timezone" role="alert"></div>
                                @error('timezone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3" data-address-region-group>
                                <label class="form-label" for="addrRegion">Region</label>
                                <select class="form-control @error('region') is-invalid @enderror"
                                        id="addrRegion" name="region" data-address-field="region" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrRegionText" name="region"
                                       data-address-field="region" data-role="text" placeholder="Region" disabled>
                                <small class="text-muted" data-address-loading="region" hidden><i class="fas fa-spinner fa-spin"></i> Loading regions&hellip;</small>
                                <div class="invalid-feedback d-block" data-address-error="region" role="alert"></div>
                            </div>
                            <div class="col-md-6 mb-3" data-address-city-group>
                                <label class="form-label" for="addrCity">City / Municipality</label>
                                <select class="form-control @error('city') is-invalid @enderror"
                                        id="addrCity" name="city" data-address-field="city" data-role="select" disabled></select>
                                <input type="text" class="form-control d-none" id="addrCityText" name="city"
                                       data-address-field="city" data-role="text" placeholder="City / Municipality" disabled>
                                <small class="text-muted" data-address-loading="city" hidden><i class="fas fa-spinner fa-spin"></i> Loading cities&hellip;</small>
                                <div class="invalid-feedback d-block" data-address-error="city" role="alert"></div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3" data-address-barangay-group>
                                <label class="form-label" for="addrBarangay">Barangay</label>
                                <select class="form-control @error('barangay') is-invalid @enderror"
                                        id="addrBarangay" name="barangay" data-address-field="barangay" disabled></select>
                                <small class="text-muted" data-address-loading="barangay" hidden><i class="fas fa-spinner fa-spin"></i> Loading barangays&hellip;</small>
                                <div class="invalid-feedback d-block" data-address-error="barangay" role="alert"></div>
                            </div>
                            <div class="col-md-6 mb-3" data-address-zip-group>
                                <label class="form-label" for="addrZip">ZIP Code</label>
                                <input type="text" class="form-control @error('zip_code') is-invalid @enderror"
                                       id="addrZip" name="zip_code" data-address-field="zip_code" value="{{ old('zip_code', $guest->zip_code ?? '') }}">
                                @error('zip_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                        <div class="mb-0" data-address-street-group>
                            <label class="form-label" for="addrStreet">Street / House No. / Subdivision</label>
                            <input type="text" class="form-control @error('street') is-invalid @enderror"
                                   id="addrStreet" name="street" data-address-field="street" value="{{ old('street', $guest->street ?? '') }}">
                            <div class="invalid-feedback d-block" data-address-error="street" role="alert"></div>
                        </div>
                    </div>
                </x-card>

                <button type="submit" class="btn btn-primary mb-4">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </form>

            <!-- Change Password (OTP-gated, mirrors the System Administrator's flow) -->
            <div class="card border-0 shadow-sm">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-lock"></i> Change Password</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">
                        For security, changing your password requires a verification code sent to your registered email
                        (<strong>{{ $user->email }}</strong>).
                    </p>

                    <form action="{{ route('guest.profile.password.otp') }}" method="POST" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-paper-plane"></i> Send Verification Code
                        </button>
                    </form>

                    <form action="{{ route('guest.profile.changePassword') }}" method="POST">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label class="form-label">Verification Code</label>
                            <input type="text" name="otp" class="form-control @error('otp') is-invalid @enderror" maxlength="6" placeholder="6-digit code" required>
                            @error('otp')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3 password-field-group">
                            <label class="form-label">New Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="new_password" class="form-control @error('new_password') is-invalid @enderror" required minlength="8">
                                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                            </div>
                            @error('new_password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3 password-field-group">
                            <label class="form-label">Confirm New Password</label>
                            <div class="password-input-wrapper">
                                <input type="password" name="new_password_confirmation" class="form-control" required minlength="8">
                                <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-key"></i> Change Password
                        </button>
                    </form>
                </div>
            </div>

            <!-- Delete Account (30-day soft-delete/restore, mirrors the mobile app's flow) -->
            <div class="card border-0 shadow-sm border-danger mt-4">
                <div class="card-header bg-danger-subtle">
                    <h5 class="mb-0 text-danger"><i class="fas fa-triangle-exclamation"></i> Delete Account</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-3">
                        Deactivates your account for 30 days. You can restore it any time during that window by simply
                        logging back in - after 30 days, it's permanently removed along with your saved information.
                    </p>
                    <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteAccountModal">
                        <i class="fas fa-user-slash"></i> Delete My Account
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteAccountModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form action="{{ route('guest.account.delete') }}" method="POST">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-triangle-exclamation text-danger"></i> Confirm Account Deletion</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>This deactivates your account for 30 days. Enter your password to confirm.</p>
                    <div class="mb-3 password-field-group">
                        <label class="form-label">Password</label>
                        <div class="password-input-wrapper">
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
                            <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                        </div>
                        @error('password')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-user-slash"></i> Delete My Account
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@if ($errors->has('password'))
    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        new bootstrap.Modal(document.getElementById('deleteAccountModal')).show();
    });
    </script>
    @endpush
@endif
@endsection

@push('scripts')
<script src="{{ asset('js/address-cascade.js') }}?v={{ filemtime(public_path('js/address-cascade.js')) }}"></script>
<script>
(function () {
    'use strict';

    // --- Date of Birth -> read-only Age (same calendar-accurate logic as register-wizard.js) ---
    var dobInput = document.getElementById('guestDob');
    var ageInput = document.getElementById('guestAge');

    function calculateAge(dobValue) {
        if (!dobValue) return null;
        var dob = new Date(dobValue + 'T00:00:00');
        if (isNaN(dob.getTime())) return null;
        var today = new Date();
        var age = today.getFullYear() - dob.getFullYear();
        var monthDiff = today.getMonth() - dob.getMonth();
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) age--;
        return age >= 0 ? age : null;
    }

    function updateAgeFromDob() {
        if (!dobInput || !ageInput) return;
        var age = calculateAge(dobInput.value);
        ageInput.value = age === null ? '' : age;
    }

    if (dobInput) {
        dobInput.addEventListener('input', updateAgeFromDob);
        dobInput.addEventListener('change', updateAgeFromDob);
    }

    // --- Mobile format hint, kept in sync with the selected Country - instant feedback
    //     only, the real check happens server-side (PhoneValidationService/ValidPhoneNumber)
    //     on submit. Also flags the one common, unambiguous mistake client-side: a
    //     Philippine-shaped number left behind after switching to another country. ---
    var countrySelect = document.getElementById('addrCountry');
    var mobileInput = document.getElementById('guestMobile');
    var mobileHint = document.getElementById('guestMobileHint');
    var PH_SHAPE = /^(09|\+639|639)\d{9}$/;

    var PHONE_FORMAT_HINTS = {
        'Philippines': '09XXXXXXXXX or +639XXXXXXXXX.',
        'United States': 'e.g. +12025550123',
        'Canada': 'e.g. +12025550123',
        'United Kingdom': 'e.g. +447911123456',
        'Australia': 'e.g. +61412345678',
        'Singapore': 'e.g. +6591234567',
        'Malaysia': 'e.g. +60123456789',
        'Japan': 'e.g. +819012345678',
        'South Korea': 'e.g. +821012345678',
        'United Arab Emirates': 'e.g. +971501234567',
        'Saudi Arabia': 'e.g. +966512345678',
        'Qatar': 'e.g. +97433123456',
        'Hong Kong': 'e.g. +85251234567',
        'New Zealand': 'e.g. +64211234567'
    };

    function updateMobileHint() {
        if (!countrySelect || !mobileHint) return;
        var country = countrySelect.value;
        var value = mobileInput ? mobileInput.value.trim() : '';
        if (country && country !== 'Philippines' && PH_SHAPE.test(value)) {
            mobileHint.textContent = 'This looks like a Philippine number - please update it for ' + country + '.';
            mobileHint.classList.add('text-danger');
            mobileHint.classList.remove('text-muted');
        } else {
            mobileHint.textContent = PHONE_FORMAT_HINTS[country] || 'Enter a valid mobile number with country code.';
            mobileHint.classList.remove('text-danger');
            mobileHint.classList.add('text-muted');
        }
    }

    if (countrySelect) {
        updateMobileHint();
        countrySelect.addEventListener('change', updateMobileHint);
    }
})();
</script>
@endpush
