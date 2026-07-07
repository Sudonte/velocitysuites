@extends('layouts.app')

@section('title', 'Amenity Requests - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-spa" title="Amenity Requests" />

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Filter -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('receptionist.amenities.index') }}" class="row g-3">
            <div class="col-md-4">
                <select name="status" class="form-control">
                    <option value="">All Status</option>
                    <option value="pending" {{ request('status') === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="approved" {{ request('status') === 'approved' ? 'selected' : '' }}>Approved</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
            </div>
        </form>
    </x-card>

    <x-card title="All Amenity Requests" icon="fas fa-list" bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Guest</th>
                    <th>Amenity</th>
                    <th>Quantity</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($amenityRequests as $req)
                    <tr>
                        <td>{{ $req->guest->user->full_name ?? 'N/A' }}</td>
                        <td>{{ $req->amenity->amenity_name ?? 'N/A' }}</td>
                        <td>{{ $req->quantity }}</td>
                        <td>₱{{ number_format($req->charge * $req->quantity, 2) }}</td>
                        <td><x-status-badge :status="$req->status" domain="amenity_request" /></td>
                        <td>
                            <form action="{{ route('receptionist.amenities.update', $req) }}" method="POST" class="d-inline">
                                @csrf
                                @method('PUT')
                                <select name="status" class="form-select form-select-sm d-inline-block" style="width: auto;">
                                    <option value="pending" {{ $req->status === 'pending' ? 'selected' : '' }}>Pending</option>
                                    <option value="approved" {{ $req->status === 'approved' ? 'selected' : '' }}>Approved</option>
                                    <option value="rejected" {{ $req->status === 'rejected' ? 'selected' : '' }}>Rejected</option>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-save"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty-state icon="fas fa-spa" message="No amenity requests yet." /></td></tr>
                @endforelse
            </tbody>
        </table>
        <x-slot:footer>
            {{ $amenityRequests->links() }}
        </x-slot:footer>
    </x-card>
</div>
@endsection
