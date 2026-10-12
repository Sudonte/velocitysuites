{{--
    Personal details of a receptionist / manager (stored in the receptionists /
    managers table, referencing the user account): home address, date of birth
    & gender, contact number. Used by Add and Edit Staff Account.
    @param \App\Models\Receptionist|\App\Models\Manager|null $profile
--}}
@php $profile = $profile ?? null; @endphp
<h6 class="form-section-heading mt-2">Home Address</h6>
<div class="row">
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="street">House No. / Street *</label>
            <input type="text" class="form-control @error('street') is-invalid @enderror" id="street" name="street" maxlength="255"
                   value="{{ old('street', $profile?->street) }}" required>
            @error('street')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="barangay">Barangay *</label>
            <input type="text" class="form-control @error('barangay') is-invalid @enderror" id="barangay" name="barangay" maxlength="100"
                   value="{{ old('barangay', $profile?->barangay) }}" required>
            @error('barangay')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="city">City / Municipality *</label>
            <input type="text" class="form-control @error('city') is-invalid @enderror" id="city" name="city" maxlength="100"
                   value="{{ old('city', $profile?->city) }}" required>
            @error('city')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="province">Province *</label>
            <input type="text" class="form-control @error('province') is-invalid @enderror" id="province" name="province" maxlength="100"
                   value="{{ old('province', $profile?->province) }}" required>
            @error('province')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<h6 class="form-section-heading mt-2">Date of Birth &amp; Gender</h6>
<div class="row">
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="date_of_birth">Date of Birth *</label>
            <input type="date" class="form-control @error('date_of_birth') is-invalid @enderror" id="date_of_birth" name="date_of_birth"
                   max="{{ now()->subYears(18)->toDateString() }}" value="{{ old('date_of_birth', $profile?->date_of_birth?->toDateString()) }}" required>
            <small class="text-muted">Staff must be at least 18 years old.</small>
            @error('date_of_birth')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="gender">Gender *</label>
            <select class="form-control @error('gender') is-invalid @enderror" id="gender" name="gender" required>
                <option value="">Select Gender</option>
                <option value="male" {{ old('gender', $profile?->gender) === 'male' ? 'selected' : '' }}>Male</option>
                <option value="female" {{ old('gender', $profile?->gender) === 'female' ? 'selected' : '' }}>Female</option>
            </select>
            @error('gender')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>

<h6 class="form-section-heading mt-2">Contact Details</h6>
<div class="row">
    <div class="col-md-6">
        <div class="form-group mb-3">
            <label for="mobile_number">Mobile Number *</label>
            <input type="text" class="form-control @error('mobile_number') is-invalid @enderror" id="mobile_number" name="mobile_number"
                   placeholder="09XXXXXXXXX" data-phone="ph" value="{{ old('mobile_number', $profile?->mobile_number) }}" required>
            @error('mobile_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>
</div>
