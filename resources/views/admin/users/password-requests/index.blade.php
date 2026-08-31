@extends('layouts.app')

@section('title', 'Password Reset Requests - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-key" title="Password Reset Requests" subtitle="Manager and Receptionist accounts awaiting a password reset.">
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to User Management
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

    <!-- Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.users.password-requests.index') }}" class="row g-3">
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                    <option value="completed" {{ request('status') === 'completed' ? 'selected' : '' }}>Completed</option>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-filter"></i> Filter
                </button>
            </div>
        </form>
    </x-card>

    <!-- Requests Table -->
    <x-card bodyClass="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Requested</th>
                    <th>Status</th>
                    <th>Failed Logins</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($requests as $req)
                    <tr>
                        <td><strong>{{ $req->user->full_name }}</strong></td>
                        <td><span class="badge badge-brand">{{ ucfirst($req->user->role) }}</span></td>
                        <td>{{ $req->user->email }}</td>
                        <td>{{ $req->created_at->format('M d, Y g:i A') }}</td>
                        <td>
                            @if($req->status === 'pending' && $req->isExpired())
                                <span class="badge bg-secondary" title="Submitted {{ $req->created_at->diffForHumans() }}">
                                    <i class="fas fa-clock"></i> Expired
                                </span>
                            @else
                                <x-status-badge :status="$req->status" domain="staff_password_reset_request" />
                            @endif
                            @if($req->processedBy)
                                <div class="small text-muted mt-1">by {{ $req->processedBy->full_name }}</div>
                            @endif
                        </td>
                        <td>
                            @if($req->user->failed_login_attempts >= 3)
                                <span class="badge bg-danger">{{ $req->user->failed_login_attempts }} attempts</span>
                            @else
                                <span class="text-muted small">{{ $req->user->failed_login_attempts }}</span>
                            @endif
                        </td>
                        <td>
                            @if($req->status === 'pending')
                                <div class="d-flex gap-2">
                                    <form action="{{ route('admin.users.password-requests.approve', $req) }}" method="POST"
                                          onsubmit="return confirm('Approve this request? {{ $req->user->full_name }} will be reset to the default password.')">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i> Approve
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#rejectModal{{ $req->id }}">
                                        <i class="fas fa-times"></i> Reject
                                    </button>
                                </div>

                                <div class="modal fade" id="rejectModal{{ $req->id }}" tabindex="-1" aria-hidden="true">
                                    <div class="modal-dialog">
                                        <form action="{{ route('admin.users.password-requests.reject', $req) }}" method="POST" class="modal-content">
                                            @csrf
                                            @method('PUT')
                                            <div class="modal-header">
                                                <h5 class="modal-title">Reject Request - {{ $req->user->full_name }}</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <label for="reason{{ $req->id }}" class="form-label">Reason</label>
                                                <textarea name="reason" id="reason{{ $req->id }}" class="form-control" rows="3" required maxlength="255"></textarea>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                                                <button type="submit" class="btn btn-danger">Reject Request</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @else
                                <span class="text-muted small">No action needed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-key" message="No password reset requests." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        {{ $requests->links() }}
    </div>
</div>
@endsection
