@extends('layouts.app')

@section('title', 'User Details - Admin')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-user" title="{{ $user->full_name }}">
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to User Management
            </a>
        </x-slot:actions>
    </x-page-header>

    <div class="row">
        <div class="col-md-6 mb-4">
            <x-card title="Account Details" icon="fas fa-id-badge" bodyClass="card-body">
                <dl class="row mb-0">
                    <dt class="col-sm-4">Full Name</dt>
                    <dd class="col-sm-8">{{ $user->full_name }}</dd>

                    <dt class="col-sm-4">Email</dt>
                    <dd class="col-sm-8">{{ $user->email }}</dd>

                    <dt class="col-sm-4">Role</dt>
                    <dd class="col-sm-8"><span class="badge badge-brand">{{ ucfirst($user->role) }}</span></dd>

                    <dt class="col-sm-4">Status</dt>
                    <dd class="col-sm-8"><x-status-badge :status="$user->status" domain="user" /></dd>

                    <dt class="col-sm-4">Last Login</dt>
                    <dd class="col-sm-8">{{ $user->last_login_at ? $user->last_login_at->timezone('Asia/Manila')->format('M d, Y h:i A') : 'Never' }}</dd>

                    <dt class="col-sm-4">Account Created</dt>
                    <dd class="col-sm-8">{{ $user->created_at->timezone('Asia/Manila')->format('M d, Y h:i A') }} (Asia/Manila)</dd>
                </dl>
            </x-card>
        </div>

        @if($user->guest)
        <div class="col-md-6 mb-4">
            <x-card title="Personal Details" icon="fas fa-address-card" bodyClass="card-body">
                <div class="d-flex align-items-center mb-3">
                    @if($user->guest->profile_picture_url)
                        <img src="{{ $user->guest->profile_picture_url }}" alt="Profile picture"
                             class="rounded-circle border" style="width:72px;height:72px;object-fit:cover;">
                    @else
                        <div class="rounded-circle bg-light border d-flex align-items-center justify-content-center"
                             style="width:72px;height:72px;">
                            <i class="fas fa-user text-muted" style="font-size:28px;"></i>
                        </div>
                    @endif
                    <div class="ms-3">
                        <div class="fw-bold">{{ $user->full_name }}</div>
                        <small class="text-muted">{{ $user->guest->profile_picture_url ? 'Profile picture on file' : 'No profile picture uploaded' }}</small>
                    </div>
                </div>

                <dl class="row mb-0">
                    <dt class="col-sm-4">Age</dt>
                    <dd class="col-sm-8">{{ $user->guest->age }}</dd>

                    <dt class="col-sm-4">Gender</dt>
                    <dd class="col-sm-8">{{ ucfirst($user->guest->gender) }}</dd>

                    <dt class="col-sm-4">Date of Birth</dt>
                    <dd class="col-sm-8">{{ optional($user->guest->date_of_birth)->format('M d, Y') }}</dd>

                    <dt class="col-sm-4">Mobile Number</dt>
                    <dd class="col-sm-8">{{ $user->guest->mobile_number }}</dd>

                    <dt class="col-sm-4">Address</dt>
                    <dd class="col-sm-8">{{ $user->guest->address }}</dd>

                    <dt class="col-sm-4">Country</dt>
                    <dd class="col-sm-8">{{ $user->guest->country ?? '—' }}</dd>

                    <dt class="col-sm-4">Timezone</dt>
                    <dd class="col-sm-8">{{ $user->guest->timezone ?? '—' }}</dd>
                </dl>
            </x-card>
        </div>
        @endif
    </div>

    @if($user->guest)
    <x-card title="Reservation History" icon="fas fa-calendar-check" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Room Type</th>
                    <th>Check-in</th>
                    <th>Check-out</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($user->guest->reservations as $reservation)
                    <tr>
                        <td>{{ $reservation->roomType->name ?? '-' }}</td>
                        <td>{{ $reservation->check_in->format('M d, Y') }}</td>
                        <td>{{ $reservation->check_out->format('M d, Y') }}</td>
                        <td><x-status-badge :status="$reservation->status" domain="reservation" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">
                            <x-empty-state icon="fas fa-calendar" message="No reservations yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
    @endif

    @if($user->guest)
    <x-card title="Profile Update History" icon="fas fa-history" bodyClass="table-responsive" class="mt-4">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Action</th>
                    <th>IP Address</th>
                </tr>
            </thead>
            <tbody>
                @forelse($user->activityLogs->sortByDesc('created_at')->take(25) as $log)
                    <tr>
                        <td>{{ $log->created_at->format('M d, Y h:i A') }}</td>
                        <td>{{ $log->action }}</td>
                        <td>{{ $log->ip_address }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">
                            <x-empty-state icon="fas fa-history" message="No recorded profile activity yet." />
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>
    @endif
</div>
@endsection