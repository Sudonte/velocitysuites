@extends('layouts.app')

@section('title', 'Edit User - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user-edit" title="Edit User: {{ $user->full_name }}" />

    <div class="row">
        <div class="col-lg-8">
            <x-card title="User Information" bodyClass="card-body">
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

                <form action="{{ route('admin.users.update', $user) }}" method="POST">
                    @csrf
                    @method('PUT')

                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="first_name">First Name *</label>
                                <input type="text" class="form-control @error('first_name') is-invalid @enderror"
                                       id="first_name" name="first_name" value="{{ old('first_name', $user->first_name) }}" required>
                                @error('first_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="middle_name">Middle Name</label>
                                <input type="text" class="form-control @error('middle_name') is-invalid @enderror"
                                       id="middle_name" name="middle_name" value="{{ old('middle_name', $user->middle_name) }}">
                                @error('middle_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group mb-3">
                                <label for="last_name">Last Name *</label>
                                <input type="text" class="form-control @error('last_name') is-invalid @enderror"
                                       id="last_name" name="last_name" value="{{ old('last_name', $user->last_name) }}" required>
                                @error('last_name')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label for="email">Email Address *</label>
                        <input type="email" class="form-control @error('email') is-invalid @enderror"
                               id="email" name="email" value="{{ old('email', $user->email) }}" required>
                        @error('email')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle"></i>
                        <strong>Note:</strong> To change the password, use the "Reset Password" action from the user list.
                    </div>

                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="role">Role *</label>
                                <select class="form-control @error('role') is-invalid @enderror"
                                        id="role" name="role" required>
                                    <option value="">Select Role</option>
                                    <option value="admin" {{ old('role', $user->role) === 'admin' ? 'selected' : '' }}>Admin</option>
                                    <option value="manager" {{ old('role', $user->role) === 'manager' ? 'selected' : '' }}>Manager</option>
                                    <option value="receptionist" {{ old('role', $user->role) === 'receptionist' ? 'selected' : '' }}>Receptionist</option>
                                    <option value="guest" {{ old('role', $user->role) === 'guest' ? 'selected' : '' }}>Guest</option>
                                </select>
                                @error('role')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group mb-3">
                                <label for="status">Status *</label>
                                <select class="form-control @error('status') is-invalid @enderror"
                                        id="status" name="status" required>
                                    <option value="active" {{ old('status', $user->status) === 'active' ? 'selected' : '' }}>Active</option>
                                    <option value="suspended" {{ old('status', $user->status) === 'suspended' ? 'selected' : '' }}>Suspended</option>
                                </select>
                                @error('status')
                                    <div class="invalid-feedback">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="{{ route('admin.users.index') }}" class="btn btn-secondary">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </form>
            </x-card>
        </div>

        <div class="col-lg-4">
            <x-card title="Account Details" bodyClass="card-body" class="mb-4">
                <p class="mb-2">
                    <strong>Email:</strong> {{ $user->email }}
                </p>
                <p class="mb-2">
                    <strong>Role:</strong> <span class="badge badge-brand">{{ ucfirst($user->role) }}</span>
                </p>
                <p class="mb-2">
                    <strong>Status:</strong> <x-status-badge :status="$user->status" domain="user" />
                </p>
                <p class="mb-2">
                    <strong>Last Login:</strong> {{ $user->last_login_at ? $user->last_login_at->format('M d, Y h:i A') : 'Never' }}
                </p>
                <p class="mb-0">
                    <strong>Member Since:</strong> {{ $user->created_at->format('M d, Y') }}
                </p>
            </x-card>

            <x-card title="Quick Actions" bodyClass="card-body">
                @if($user->status === 'active')
                    <form action="{{ route('admin.users.deactivate', $user) }}" method="POST" class="mb-2">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-warning w-100"
                                onclick="return confirm('Deactivate this user?')">
                            <i class="fas fa-ban"></i> Deactivate
                        </button>
                    </form>
                @else
                    <form action="{{ route('admin.users.reactivate', $user) }}" method="POST" class="mb-2">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="btn btn-success w-100">
                            <i class="fas fa-undo"></i> Reactivate
                        </button>
                    </form>
                @endif

                <form action="{{ route('admin.users.resetPassword', $user) }}" method="POST" class="mb-2">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="btn btn-secondary w-100"
                            onclick="return confirm('Reset password for this user?')">
                        <i class="fas fa-key"></i> Reset Password
                    </button>
                </form>

                <form action="{{ route('admin.users.destroy', $user) }}" method="POST">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn-danger w-100"
                            onclick="return confirm('Delete this user? This action cannot be undone.')">
                        <i class="fas fa-trash"></i> Delete User
                    </button>
                </form>
            </x-card>
        </div>
    </div>
</div>
@endsection
