@extends('layouts.app')

@section('title', 'My Profile')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user" title="My Profile" subtitle="Manage your account information and security settings." />

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

    @php
        $isElevatedStaff = in_array($user->role, ['manager', 'receptionist']);
    @endphp

    <div class="row">
        <!-- Profile Card -->
        <div class="col-lg-4 mb-4">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center">
                    <x-user-avatar :user="$user" :size="100" />
                    <h4 class="mt-3 mb-1">{{ $user->full_name }}</h4>
                    <p class="text-muted">{{ $user->email }}</p>
                    <span class="badge badge-brand">{{ $user->role_label }}</span>
                    <span class="badge bg-{{ $user->status === 'active' ? 'success' : 'danger' }}">{{ ucfirst($user->status) }}</span>
                    <hr>
                    <p class="mb-1 text-muted small">
                        <i class="fas fa-calendar"></i>
                        Joined {{ $user->created_at->format('M d, Y') }}
                    </p>
                    @if($user->last_login_at)
                        <p class="mb-0 text-muted small">
                            <i class="fas fa-clock"></i>
                            Last login {{ $user->last_login_at->diffForHumans() }}
                        </p>
                    @endif
                </div>
            </div>
        </div>

        <!-- Forms -->
        <div class="col-lg-8">
            @if($isElevatedStaff)
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    Your account name, email, and password are managed by the System Administrator.
                    Contact them if you need any of this updated or your password reset.
                </div>

                <x-card title="Account Details" icon="fas fa-id-badge">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Full Name</dt>
                        <dd class="col-sm-8">{{ $user->full_name }}</dd>

                        <dt class="col-sm-4">Email</dt>
                        <dd class="col-sm-8">{{ $user->email }}</dd>

                        <dt class="col-sm-4">Role</dt>
                        <dd class="col-sm-8">{{ $user->role_label }}</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">{{ ucfirst($user->status) }}</dd>

                        <dt class="col-sm-4">Account Created</dt>
                        <dd class="col-sm-8">{{ $user->created_at->format('M d, Y h:i A') }}</dd>

                        <dt class="col-sm-4">Last Login</dt>
                        <dd class="col-sm-8">{{ $user->last_login_at ? $user->last_login_at->format('M d, Y h:i A') : 'Never' }}</dd>
                    </dl>
                </x-card>
            @else
                <!-- Profile Picture -->
                <x-card title="Profile Picture" icon="fas fa-camera" class="mb-4">
                    <div class="d-flex align-items-center flex-wrap gap-3 mb-3">
                        <img id="profilePicturePreview" alt="Profile picture"
                             class="rounded-circle border"
                             style="width:96px;height:96px;object-fit:cover;{{ $user->profile_picture_url ? '' : ' display:none;' }}"
                             src="{{ $user->profile_picture_url ?? 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBTAA7' }}">
                        <div id="profilePicturePlaceholder"
                             class="user-avatar-initials"
                             style="width:96px;height:96px;font-size:32px;{{ $user->profile_picture_url ? ' display:none;' : '' }}">
                            {{ $user->initials }}
                        </div>
                        <div>
                            @if($user->canChangeProfilePicture())
                                <p class="text-muted small mb-0">JPG, JPEG, or PNG. Max size 5MB. You can change this once a month.</p>
                            @else
                                <p class="text-warning small mb-0">
                                    <i class="fas fa-hourglass-half"></i>
                                    You've already changed your picture this month. Next available:
                                    <strong>{{ $user->nextProfilePictureChangeDate()->format('M d, Y') }}</strong>.
                                </p>
                            @endif
                        </div>
                    </div>

                    <div class="d-flex flex-wrap align-items-start gap-2">
                        <form action="{{ route('profile.picture.update') }}" method="POST" enctype="multipart/form-data" class="d-flex flex-wrap align-items-start gap-2">
                            @csrf
                            <div class="flex-grow-1" style="min-width:220px;">
                                <input type="file" name="profile_picture" id="profilePictureInput" accept="image/jpeg,image/png,image/jpg"
                                       class="form-control @error('profile_picture') is-invalid @enderror"
                                       {{ $user->canChangeProfilePicture() ? '' : 'disabled' }} required>
                                @error('profile_picture')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <button type="submit" class="btn btn-primary" {{ $user->canChangeProfilePicture() ? '' : 'disabled' }}>
                                <i class="fas fa-upload"></i> Upload
                            </button>
                        </form>

                        @if($user->profile_picture)
                            <form action="{{ route('profile.picture.remove') }}" method="POST" onsubmit="return confirm('Remove your profile picture?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </form>
                        @endif
                    </div>
                </x-card>

                <!-- Update Info -->
                @php
                    // Guests keep age/gender/DOB/mobile/address on the linked `guests` row -
                    // the same one the mobile app's Api\ProfileController reads/writes - not on
                    // `users`. Admins (no guest row) keep using their own `users` columns.
                    $profileSource = $user->role === 'guest' ? $guest : $user;
                @endphp
                <form action="{{ route('profile.update') }}" method="POST">
                    @csrf
                    @method('PUT')

                    <x-card title="Personal Information" icon="fas fa-id-card" class="mb-4">
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" value="{{ $user->email }}" disabled readonly>
                            <small class="text-muted"><i class="fas fa-lock"></i> Your registered email address cannot be changed.</small>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Last Name</label>
                                <input type="text" name="last_name" class="form-control @error('last_name') is-invalid @enderror" value="{{ old('last_name', $user->last_name) }}" required>
                                @error('last_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">First Name</label>
                                <input type="text" name="first_name" class="form-control @error('first_name') is-invalid @enderror" value="{{ old('first_name', $user->first_name) }}" required>
                                @error('first_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control @error('middle_name') is-invalid @enderror" value="{{ old('middle_name', $user->middle_name) }}">
                                @error('middle_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Age</label>
                                <input type="number" name="age" class="form-control @error('age') is-invalid @enderror" value="{{ old('age', optional($profileSource)->age) }}" min="1" max="120" required>
                                @error('age')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="date_of_birth" class="form-control @error('date_of_birth') is-invalid @enderror" value="{{ old('date_of_birth', optional(optional($profileSource)->date_of_birth)->format('Y-m-d')) }}" required>
                                @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-control @error('gender') is-invalid @enderror" required>
                                    <option value="">Select Gender</option>
                                    <option value="male" {{ old('gender', optional($profileSource)->gender) == 'male' ? 'selected' : '' }}>Male</option>
                                    <option value="female" {{ old('gender', optional($profileSource)->gender) == 'female' ? 'selected' : '' }}>Female</option>
                                </select>
                                @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="mb-0">
                            <label class="form-label">Mobile Number</label>
                            <input type="text" name="mobile_number" class="form-control @error('mobile_number') is-invalid @enderror" value="{{ old('mobile_number', optional($profileSource)->mobile_number) }}" placeholder="09XXXXXXXXX" required>
                            <small class="text-muted">Philippine format: starts with 09 or +639</small>
                            @error('mobile_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </x-card>

                    <x-card title="Address" icon="fas fa-map-marker-alt" class="mb-4">
                        <small class="text-muted d-block mb-3">Select your location step by step - Region, Province, City, and Barangay load automatically based on your previous selection.</small>

                        <div data-address-cascade data-initial="{{ json_encode([
                            'country' => old('country', optional($profileSource)->country ?? 'Philippines'),
                            'region' => old('region', optional($profileSource)->region),
                            'province' => old('province', optional($profileSource)->province),
                            'city' => old('city', optional($profileSource)->city),
                            'barangay' => old('barangay', optional($profileSource)->barangay),
                            'timezone' => old('timezone', optional($profileSource)->timezone),
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
                                           id="addrZip" name="zip_code" data-address-field="zip_code" value="{{ old('zip_code', optional($profileSource)->zip_code) }}">
                                    @error('zip_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            </div>
                            <div class="mb-0" data-address-street-group>
                                <label class="form-label" for="addrStreet">Street / House No. / Subdivision</label>
                                <input type="text" class="form-control @error('street') is-invalid @enderror"
                                       id="addrStreet" name="street" data-address-field="street" value="{{ old('street', optional($profileSource)->street) }}" required>
                                <div class="invalid-feedback d-block" data-address-error="street" role="alert"></div>
                                @error('street')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </x-card>

                    <button type="submit" class="btn btn-primary mt-2 mb-4">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </form>

                <!-- Change Password (OTP-gated) -->
                <x-card title="Change Password" icon="fas fa-lock">
                    <p class="text-muted small">
                        For security, changing your password requires a verification code sent to your registered email
                        (<strong>{{ $user->email }}</strong>).
                    </p>

                    <form action="{{ route('profile.password.otp') }}" method="POST" class="mb-3">
                        @csrf
                        <button type="submit" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-paper-plane"></i> Send Verification Code
                        </button>
                    </form>

                    <form action="{{ route('profile.changePassword') }}" method="POST">
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
                </x-card>
            @endif
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script src="{{ asset('js/address-cascade.js') }}?v={{ filemtime(public_path('js/address-cascade.js')) }}"></script>
@endpush
