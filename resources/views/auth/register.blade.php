@extends('layouts.guest')

@section('title', 'Register - Hotel Booking System')

@push('styles')
<style>
    .register-card {
        max-width: 640px;
    }
    .form-section-title {
        font-weight: 700;
        color: var(--primary-color);
        text-transform: uppercase;
        font-size: 0.85rem;
        letter-spacing: 0.04em;
        margin-bottom: 0.75rem;
        padding-bottom: 0.5rem;
        border-bottom: 2px solid var(--border-color);
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
</style>
@endpush

@section('content')
<div class="auth-card register-card">
    <div class="logo">
        <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites" style="height: 90px; width: auto;">
        <p class="text-muted">Create Account</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-error" role="alert">
            <strong>Error!</strong>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('register.post') }}">
        @csrf

        <div class="form-section">
            <div class="form-section-title">Account Details</div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input type="email" class="form-control @error('email') is-invalid @enderror"
                       id="email" name="email" value="{{ old('email') }}" required>
                @error('email')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" class="form-control @error('password') is-invalid @enderror"
                               id="password" name="password" required>
                        @error('password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="password_confirmation">Confirm Password</label>
                        <input type="password" class="form-control"
                               id="password_confirmation" name="password_confirmation" required>
                    </div>
                </div>
            </div>
        </div>

        <div class="form-section">
            <div class="form-section-title">Personal Information</div>

            <div class="form-group">
                <label>Full Name</label>
                <div class="row g-2">
                    <div class="col-md-4">
                        <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                               id="first_name" name="first_name" value="{{ old('first_name') }}" required>
                        <small class="field-caption">First Name</small>
                        @error('first_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <input type="text" class="form-control @error('middle_name') is-invalid @enderror"
                               id="middle_name" name="middle_name" value="{{ old('middle_name') }}">
                        <small class="field-caption">Middle Name (optional)</small>
                        @error('middle_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                    <div class="col-md-4">
                        <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                               id="last_name" name="last_name" value="{{ old('last_name') }}" required>
                        <small class="field-caption">Last Name</small>
                        @error('last_name')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="age">Age</label>
                        <input type="number" class="form-control @error('age') is-invalid @enderror"
                               id="age" name="age" value="{{ old('age') }}" required min="1">
                        @error('age')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <label for="gender">Gender</label>
                        <select class="form-control @error('gender') is-invalid @enderror"
                                id="gender" name="gender" required>
                            <option value="">Select Gender</option>
                            <option value="male" {{ old('gender') == 'male' ? 'selected' : '' }}>Male</option>
                            <option value="female" {{ old('gender') == 'female' ? 'selected' : '' }}>Female</option>
                            <option value="other" {{ old('gender') == 'other' ? 'selected' : '' }}>Other</option>
                        </select>
                        @error('gender')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="date_of_birth">Date of Birth</label>
                <input type="date" class="form-control @error('date_of_birth') is-invalid @enderror"
                       id="date_of_birth" name="date_of_birth" value="{{ old('date_of_birth') }}" required>
                @error('date_of_birth')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="mobile_number">Mobile Number</label>
                <input type="text" class="form-control @error('mobile_number') is-invalid @enderror"
                       id="mobile_number" name="mobile_number" value="{{ old('mobile_number') }}" required>
                @error('mobile_number')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <textarea class="form-control @error('address') is-invalid @enderror"
                          id="address" name="address" required>{{ old('address') }}</textarea>
                @error('address')
                    <div class="invalid-feedback">{{ $message }}</div>
                @enderror
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-2 mt-3">
            <i class="fas fa-user-plus"></i> Create Account
        </button>
    </form>

    <hr>

    <div class="text-center">
        <p>Already have an account? <a href="{{ route('login') }}">Login here</a></p>
    </div>
</div>
@endsection
