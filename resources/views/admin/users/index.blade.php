@extends('layouts.app')

@section('title', 'User Management - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-users" title="User Management">
        <x-slot:actions>
            <a href="{{ route('admin.users.password-requests.index') }}" class="btn btn-outline-primary position-relative">
                <i class="fas fa-key"></i> Password Reset Requests
                @if($pendingResetRequestsCount > 0)
                    <span class="badge rounded-pill bg-danger ms-1">{{ $pendingResetRequestsCount }}</span>
                @endif
            </a>
            <a href="{{ route('admin.users.create') }}" class="btn btn-primary">
                <i class="fas fa-user-plus"></i> Add Staff Account
            </a>
        </x-slot:actions>
    </x-page-header>

    <!-- Alerts -->
    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if (session('error'))
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle"></i> {{ session('error') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.users.index') }}" class="row g-3">
            <div class="col-md-4">
                <input type="text" name="search" class="form-control"
                       placeholder="Search by name or email" value="{{ request('search') }}">
            </div>
            <div class="col-md-3">
                <select name="role" class="form-control">
                    <option value="">All Roles</option>
                    <option value="admin" {{ request('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                    <option value="manager" {{ request('role') === 'manager' ? 'selected' : '' }}>Manager</option>
                    <option value="receptionist" {{ request('role') === 'receptionist' ? 'selected' : '' }}>Receptionist</option>
                    <option value="guest" {{ request('role') === 'guest' ? 'selected' : '' }}>Guest</option>
                </select>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search
                </button>
            </div>
        </form>
    </x-card>

    <!-- Users Table -->
    <x-card bodyClass="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Registered</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($users as $user)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <x-user-avatar :user="$user" :size="40" class="flex-shrink-0" />
                                <strong>{{ $user->full_name }}</strong>
                            </div>
                        </td>
                        <td>{{ $user->email }}</td>
                        <td>
                            <span class="badge badge-brand">{{ ucfirst($user->role) }}</span>
                        </td>
                        <td>
                            <x-status-badge :status="$user->status" domain="user" />
                        </td>
                        <td>{{ $user->created_at->timezone('Asia/Manila')->format('M d, Y • g:i A') }}</td>
                        <td>
                            {{ $user->last_login_at ? $user->last_login_at->timezone('Asia/Manila')->format('M d, Y h:i A') : 'Never' }}
                        </td>
                        <td>
                            {{-- Single uniform-width dropdown per row (instead of a variable number of
                                 separate buttons) so the Actions column stays aligned regardless of how
                                 many actions a role has - guest/admin rows get just View, staff rows get
                                 the full set, but the toggle button itself is always the same size. --}}
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button"
                                        data-bs-toggle="dropdown" aria-expanded="false">
                                    <i class="fas fa-ellipsis-v"></i> Actions
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="{{ route('admin.users.show', $user) }}">
                                            <i class="fas fa-eye me-2"></i> View
                                        </a>
                                    </li>

                                    @if(in_array($user->role, ['manager', 'receptionist']))
                                        <li>
                                            <a class="dropdown-item" href="{{ route('admin.users.edit', $user) }}">
                                                <i class="fas fa-edit me-2"></i> Edit
                                            </a>
                                        </li>
                                        <li>
                                            @if($user->status === 'active')
                                                <form action="{{ route('admin.users.deactivate', $user) }}" method="POST"
                                                      onsubmit="return confirm('Deactivate this user?')">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="dropdown-item text-warning">
                                                        <i class="fas fa-ban me-2"></i> Deactivate
                                                    </button>
                                                </form>
                                            @else
                                                <form action="{{ route('admin.users.reactivate', $user) }}" method="POST"
                                                      onsubmit="return confirm('Reactivate this user?')">
                                                    @csrf
                                                    @method('PUT')
                                                    <button type="submit" class="dropdown-item text-success">
                                                        <i class="fas fa-undo me-2"></i> Reactivate
                                                    </button>
                                                </form>
                                            @endif
                                        </li>
                                        <li>
                                            <form action="{{ route('admin.users.resetPassword', $user) }}" method="POST"
                                                  onsubmit="return confirm('Reset password to the default (velocitysuites123)?')">
                                                @csrf
                                                @method('PUT')
                                                <button type="submit" class="dropdown-item">
                                                    <i class="fas fa-key me-2"></i> Reset Password
                                                </button>
                                            </form>
                                        </li>
                                    @endif
                                </ul>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-users" message="No users found." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $users->links() }}
    </div>
</div>
@endsection
