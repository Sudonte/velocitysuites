@extends('layouts.app')

@section('title', 'Add Staff Account - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user-plus" title="Add Staff Account" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="Staff Details" bodyClass="card-body">
                @if ($errors->any())
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Validation Errors:</strong>
                        <ul class="mb-0">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                @endif

                <form action="{{ route('admin.users.store') }}" method="POST">
                    @csrf

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="last_name">Last Name *</label>
                                <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                                       id="last_name" name="last_name" value="{{ old('last_name') }}" required>
                                @error('last_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="first_name">First Name *</label>
                                <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                                       id="first_name" name="first_name" value="{{ old('first_name') }}" required>
                                @error('first_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" class="form-control @error('middle_name') is-invalid @enderror"
                                       id="middle_name" name="middle_name" value="{{ old('middle_name') }}">
                                @error('middle_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="role">Role *</label>
                        <select class="form-control @error('role') is-invalid @enderror"
                                id="role" name="role" required>
                            <option value="">Select Role</option>
                            <option value="manager" {{ old('role') === 'manager' ? 'selected' : '' }}>Manager</option>
                            <option value="receptionist" {{ old('role') === 'receptionist' ? 'selected' : '' }}>Receptionist</option>
                        </select>
                        @error('role')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        The login email and password are generated automatically - see "How this works" for details.
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Create Account
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="How this works" bodyClass="card-body">
                <ul class="list-unstyled">
                    <li class="mb-2">
                        <i class="fas fa-envelope text-brand"></i>
                        <strong>Login email:</strong>
                    </li>
                    <li class="ms-4 mb-3">Generated as <code>lastname.role@hotel.com</code> (e.g. <code>nogas.manager@hotel.com</code>).</li>

                    <li class="mb-2">
                        <i class="fas fa-lock text-brand"></i>
                        <strong>Default password:</strong>
                    </li>
                    <li class="ms-4 mb-3">Every new account starts on <code>velocitysuites123</code>. They'll be asked to set their own permanent password the first time they log in.</li>

                    <li class="mb-2">
                        <i class="fas fa-key text-brand"></i>
                        <strong>Lost password:</strong>
                    </li>
                    <li class="ms-4">Managers and receptionists don't self-reset - if they forget their password, reset it back to the default from the user list.</li>
                </ul>
            </x-card>
        </div>
    </div>
</div>
@endsection