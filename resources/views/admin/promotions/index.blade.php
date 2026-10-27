@extends('layouts.app')

@section('title', 'Promos & Discounts - Admin')
@section('livePage', '1')

@section('content')
<div class="container-fluid py-4">
    @include('admin.promotions.partials.section-tabs', ['active' => 'promos'])

    @if (session('success'))
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> {{ session('success') }}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    @endif

    <!-- Search and Filter -->
    <x-card bodyClass="card-body" class="mb-3">
        <form method="GET" action="{{ route('admin.promotions.index') }}" class="row g-2 align-items-end">
            <div class="col-lg-3 col-md-6">
                <label class="form-label small mb-1" for="promoSearch">Search</label>
                <input type="search" name="search" id="promoSearch" class="form-control" placeholder="Promo name or description" value="{{ $filters['search'] }}">
            </div>
            <div class="col-lg-2 col-md-6">
                <label class="form-label small mb-1" for="promoRoomType">Room type</label>
                <select name="room_type" id="promoRoomType" class="form-select">
                    <option value="">Any</option>
                    <option value="all" {{ $filters['room_type'] === 'all' ? 'selected' : '' }}>All room types only</option>
                    @foreach($roomTypes as $type)
                        <option value="{{ $type->id }}" {{ $filters['room_type'] === (string) $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small mb-1" for="promoIncludes">Includes</label>
                <select name="includes" id="promoIncludes" class="form-select">
                    <option value="">Anything</option>
                    <option value="amenities" {{ $filters['includes'] === 'amenities' ? 'selected' : '' }}>Amenities only</option>
                    <option value="discount" {{ $filters['includes'] === 'discount' ? 'selected' : '' }}>Discount only</option>
                    <option value="both" {{ $filters['includes'] === 'both' ? 'selected' : '' }}>Amenities + discount</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-4">
                <label class="form-label small mb-1" for="promoPeriod">Period</label>
                <select name="period" id="promoPeriod" class="form-select">
                    <option value="">Any time</option>
                    <option value="running" {{ $filters['period'] === 'running' ? 'selected' : '' }}>Running today</option>
                    <option value="upcoming" {{ $filters['period'] === 'upcoming' ? 'selected' : '' }}>Upcoming</option>
                    <option value="ended" {{ $filters['period'] === 'ended' ? 'selected' : '' }}>Ended</option>
                </select>
            </div>
            <div class="col-lg-1 col-md-4">
                <label class="form-label small mb-1" for="promoStatus">Status</label>
                <select name="status" id="promoStatus" class="form-select">
                    <option value="">All</option>
                    <option value="active" {{ $filters['status'] === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="inactive" {{ $filters['status'] === 'inactive' ? 'selected' : '' }}>Inactive</option>
                    <option value="archived" {{ $filters['status'] === 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </div>
            <div class="col-lg-2 col-md-12 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1"><i class="fas fa-search"></i> Search</button>
                @if(array_filter($filters))
                    <a href="{{ route('admin.promotions.index') }}" class="btn btn-outline-secondary" title="Clear filters" aria-label="Clear filters"><i class="fas fa-times"></i></a>
                @endif
            </div>
        </form>
    </x-card>

    <!-- Promotions Table -->
    <x-card bodyClass="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Promo Name</th>
                    <th>Free Amenities</th>
                    <th>Discount</th>
                    <th>Room Type</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($promotions as $promotion)
                    <tr>
                        <td>
                            <strong>{{ $promotion->promo_name }}</strong>
                            @if($promotion->description)
                                <br><small class="text-muted">{{ Str::limit($promotion->description, 60) }}</small>
                            @endif
                        </td>
                        <td>
                            <small>
                                @forelse($promotion->amenities as $amenity)
                                    {{ $amenity->pivot->quantity }}× {{ $amenity->amenity_name }}@if(!$loop->last), @endif
                                @empty
                                    <span class="text-muted">-</span>
                                @endforelse
                            </small>
                        </td>
                        <td>
                            @if($promotion->hasDiscount())
                                <span class="badge bg-info">{{ $promotion->discountLabel() }}</span>
                                <br><small class="text-muted">{{ $promotion->roomType ? $promotion->roomType->name . ' rate only' : 'every room rate' }}</small>
                            @else
                                <span class="text-muted">-</span>
                            @endif
                        </td>
                        <td>
                            @if($promotion->roomType)
                                <span class="badge bg-secondary">{{ $promotion->roomType->name }}</span>
                            @else
                                <span class="text-muted">All</span>
                            @endif
                        </td>
                        <td>
                            <small>
                                {{ $promotion->start_date->format('M d, Y') }}<br>
                                <span class="text-muted">to</span> {{ $promotion->end_date->format('M d, Y') }}
                            </small>
                        </td>
                        <td>
                            <x-status-badge :status="$promotion->status" domain="active_flag" />
                        </td>
                        <td class="table-actions">
                            <a href="{{ route('admin.promotions.edit', $promotion) }}" class="btn btn-outline-secondary btn-sm btn-icon" title="Edit" aria-label="Edit">
                                <i class="fas fa-pen"></i></a>
                            <form action="{{ route('admin.promotions.toggle', $promotion) }}" method="POST" class="d-inline" data-confirm="It will switch between active and inactive." data-confirm-title="Toggle this promotion status?" data-confirm-button="Change Status" data-confirm-variant="warning">
                                @csrf
                                @method('PUT')
                                <button type="submit" class="btn btn-sm btn-outline-{{ $promotion->status === 'active' ? 'warning' : 'success' }} btn-icon" title="{{ $promotion->status === 'active' ? 'Deactivate' : 'Activate' }}" aria-label="{{ $promotion->status === 'active' ? 'Deactivate' : 'Activate' }}">
                                    <i class="fas fa-{{ $promotion->status === 'active' ? 'ban' : 'check' }}"></i></button>
                            </form>
                            @if($promotion->isArchived())
                                <form action="{{ route('admin.promotions.unarchive', $promotion) }}" method="POST" class="d-inline">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-outline-success btn-sm btn-icon" title="Restore" aria-label="Restore">
                                        <i class="fas fa-box-open"></i></button>
                                </form>
                            @else
                                <form action="{{ route('admin.promotions.archive', $promotion) }}" method="POST" class="d-inline" data-confirm="It is switched off and hidden from the list. Find it again with Status: Archived to restore it." data-confirm-title="Archive this promotion?" data-confirm-button="Archive" data-confirm-variant="secondary">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="btn btn-outline-secondary btn-sm btn-icon" title="Archive" aria-label="Archive">
                                        <i class="fas fa-box-archive"></i></button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">
                            <x-empty-state icon="fas fa-tag" message="No promotions found." />
                            <p class="text-center">
                                <a href="{{ route('admin.promotions.create') }}">Create one now</a>
                            </p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-card>

    <!-- Pagination -->
    <div class="d-flex justify-content-center mt-4">
        <x-pagination :paginator="$promotions" />
    </div>
</div>
@endsection
