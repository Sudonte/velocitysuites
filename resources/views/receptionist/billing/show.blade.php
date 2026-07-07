@extends('layouts.app')

@section('title', 'Billing Workspace - Receptionist')

@section('content')
<div class="container-fluid py-4">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <a href="{{ route('receptionist.billing.index') }}" class="btn btn-sm btn-secondary mb-2">
                        <i class="fas fa-arrow-left"></i> Back to Billing List
                    </a>
                    <h1 class="mb-0">
                        <i class="fas fa-cash-register"></i> Billing Workspace
                    </h1>
                    @if($billing->booking && $billing->booking->reservation)
                        <p class="text-muted">
                            Reservation #{{ $billing->booking->reservation->id }} —
                            Guest: {{ $billing->booking->reservation->guest->user->full_name ?? 'N/A' }} —
                            Room: {{ $billing->booking->reservation->room->room_number ?? 'N/A' }}
                            ({{ $billing->booking->reservation->room->room_name ?? 'N/A' }})
                        </p>
                    @endif
                </div>
                <div>
                    <span class="badge bg-{{ $billing->billing_status === 'paid' ? 'success' : ($billing->billing_status === 'confirmed' ? 'info' : ($billing->billing_status === 'partial' ? 'warning' : 'secondary')) }} fs-6">
                        {{ ucfirst($billing->billing_status) }}
                    </span>
                </div>
            </div>
        </div>
    </div>

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

    <!-- Running Total Banner -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm" style="background: linear-gradient(135deg, #C1121F 0%, #780000 100%); color: white;">
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-md-3">
                            <p class="mb-1 opacity-75">Room Charges</p>
                            <h4 class="mb-0">₱{{ number_format($billing->room_charge, 2) }}</h4>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1 opacity-75">Additional Charges</p>
                            <h4 class="mb-0">₱{{ number_format($billing->additional_charges_total, 2) }}</h4>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1 opacity-75">Discount</p>
                            <h4 class="mb-0 text-success">-₱{{ number_format($billing->discount, 2) }}</h4>
                        </div>
                        <div class="col-md-3">
                            <p class="mb-1 opacity-75">Total Due</p>
                            <h3 class="mb-0 fw-bold">₱{{ number_format($billing->running_total, 2) }}</h3>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Left Column: Bill Details and Additional Charges -->
        <div class="col-lg-8">
            <!-- Base Charges Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-calculator"></i> Base Charges</h5>
                        <span class="badge bg-light text-dark">Auto-calculated</span>
                    </div>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td>Room Charge ({{ $billing->booking->reservation->check_in->format('M d') }} - {{ $billing->booking->reservation->check_out->format('M d, Y') }})</td>
                            <td class="text-end">₱{{ number_format($billing->room_charge, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Additional Guest Fee</td>
                            <td class="text-end">₱{{ number_format($billing->additional_guest_fee, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Amenity Charges</td>
                            <td class="text-end">₱{{ number_format($billing->amenity_charge, 2) }}</td>
                        </tr>
                        <tr>
                            <td><strong>Subtotal</strong></td>
                            <td class="text-end"><strong>₱{{ number_format($billing->room_charge + $billing->additional_guest_fee + $billing->amenity_charge, 2) }}</strong></td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Additional Charges Card (Workspace) -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #ffc107; color: #333;">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Additional Charges</h5>
                        @if($billing->billing_status !== 'paid')
                            <button class="btn btn-sm btn-dark" data-bs-toggle="modal" data-bs-target="#addChargeModal">
                                <i class="fas fa-plus"></i> Add Charge
                            </button>
                        @endif
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Description</th>
                                <th>Notes</th>
                                <th class="text-end">Amount</th>
                                @if($billing->billing_status !== 'paid')
                                    <th class="text-end">Actions</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($billing->additionalCharges as $charge)
                                <tr>
                                    <td>
                                        <span class="badge bg-{{ match($charge->category) {
                                            'damage' => 'danger',
                                            'lost_item' => 'warning',
                                            'broken_equipment' => 'secondary',
                                            'mini_bar' => 'info',
                                            'laundry' => 'primary',
                                            default => 'secondary'
                                        } }}">
                                            {{ $charge->category_label }}
                                        </span>
                                    </td>
                                    <td>{{ $charge->description }}</td>
                                    <td><small class="text-muted">{{ $charge->notes ?? '-' }}</small></td>
                                    <td class="text-end">₱{{ number_format($charge->amount, 2) }}</td>
                                    @if($billing->billing_status !== 'paid')
                                        <td class="text-end">
                                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#editChargeModal{{ $charge->id }}">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <form action="{{ route('receptionist.billing.additional-charge.destroy', $charge) }}" method="POST" class="d-inline">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove this charge?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ $billing->billing_status !== 'paid' ? 5 : 4 }}" class="text-center text-muted py-4">
                                        <i class="fas fa-info-circle"></i> No additional charges. Click "Add Charge" to add extras like mini-bar, damage fees, etc.
                                    </td>
                                </tr>
                            @endforelse
                            @if($billing->additionalCharges->count() > 0)
                                <tr class="table-light">
                                    <td colspan="3"><strong>Total Additional Charges</strong></td>
                                    <td class="text-end"><strong>₱{{ number_format($billing->additional_charges_total, 2) }}</strong></td>
                                    @if($billing->billing_status !== 'paid')
                                        <td></td>
                                    @endif
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Discount Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #28a745; color: white;">
                    <h5 class="mb-0"><i class="fas fa-tag"></i> Discount Applied</h5>
                </div>
                <div class="card-body">
                    <table class="table table-borderless mb-0">
                        <tr>
                            <td>Discount</td>
                            <td class="text-end text-success">-₱{{ number_format($billing->discount, 2) }}</td>
                        </tr>
                    </table>
                </div>
            </div>

            <!-- Payment History -->
            <div class="card border-0 shadow-sm">
                <div class="card-header" style="background-color: #17a2b8; color: white;">
                    <h5 class="mb-0"><i class="fas fa-history"></i> Payment History</h5>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Method</th>
                                <th>Reference</th>
                                <th class="text-end">Amount</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($billing->payments as $payment)
                                <tr>
                                    <td>{{ $payment->payment_date->format('M d, Y h:i A') }}</td>
                                    <td>{{ ucfirst($payment->payment_method) }}</td>
                                    <td>{{ $payment->reference_number ?? '—' }}</td>
                                    <td class="text-end">₱{{ number_format($payment->amount_paid, 2) }}</td>
                                    <td>
                                        <span class="badge bg-{{ $payment->payment_status === 'completed' ? 'success' : ($payment->payment_status === 'failed' ? 'danger' : 'warning') }}">
                                            {{ ucfirst($payment->payment_status) }}
                                        </span>
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="text-center text-muted py-4">No payments yet.</td></tr>
                            @endforelse
                            @if($billing->payments->count() > 0)
                                <tr class="table-light">
                                    <td colspan="3"><strong>Total Paid</strong></td>
                                    <td class="text-end"><strong>₱{{ number_format($amountPaid, 2) }}</strong></td>
                                    <td></td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Right Column: Actions -->
        <div class="col-lg-4">
            <!-- Balance Card -->
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header" style="background-color: #C1121F; color: white;">
                    <h5 class="mb-0"><i class="fas fa-wallet"></i> Balance Due</h5>
                </div>
                <div class="card-body text-center">
                    <h2 class="mb-3" style="color: {{ $balance > 0 ? '#dc3545' : '#28a745' }};">
                        ₱{{ number_format($balance, 2) }}
                    </h2>
                    @if($balance <= 0)
                        <p class="text-success mb-0">
                            <i class="fas fa-check-circle"></i> Bill fully paid
                        </p>
                    @endif
                </div>
            </div>

            <!-- Record Payment -->
            @if($billing->billing_status !== 'paid' && $balance > 0)
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header" style="background-color: #C1121F; color: white;">
                        <h5 class="mb-0"><i class="fas fa-credit-card"></i> Record Payment</h5>
                    </div>
                    <div class="card-body">
                        <form action="{{ route('receptionist.billing.payment.store', $billing) }}" method="POST">
                            @csrf

                            <div class="mb-3">
                                <label class="form-label">Amount <span class="text-danger">*</span></label>
                                <input type="number" step="0.01" min="0.01" max="{{ $balance }}" name="amount_paid" class="form-control" value="{{ $balance }}" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Payment Method <span class="text-danger">*</span></label>
                                <select name="payment_method" class="form-control" required>
                                    <option value="cash">Cash</option>
                                    <option value="gcash">GCash</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Reference Number <small class="text-muted">(for GCash)</small></label>
                                <input type="text" name="reference_number" class="form-control" placeholder="Auto-generated for cash">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Status <span class="text-danger">*</span></label>
                                <select name="payment_status" class="form-control" required>
                                    <option value="completed">Completed</option>
                                    <option value="pending">Pending</option>
                                    <option value="failed">Failed</option>
                                </select>
                            </div>

                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-save"></i> Record Payment
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <!-- Confirm Bill -->
            @if(in_array($billing->billing_status, ['pending', 'partial']))
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header" style="background-color: #28a745; color: white;">
                        <h5 class="mb-0"><i class="fas fa-check-double"></i> Confirm Bill</h5>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">Review all charges and confirm the final amount before proceeding to payment.</p>
                        <form action="{{ route('receptionist.billing.confirm', $billing) }}" method="POST">
                            @csrf
                            <button type="submit" class="btn btn-success w-100">
                                <i class="fas fa-check"></i> Confirm Final Amount
                            </button>
                        </form>
                    </div>
                </div>
            @endif

            <!-- Guest Info -->
            @if($billing->booking && $billing->booking->reservation)
                <div class="card border-0 shadow-sm">
                    <div class="card-header" style="background-color: #6c757d; color: white;">
                        <h5 class="mb-0"><i class="fas fa-user"></i> Guest Information</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-1"><strong>Name:</strong> {{ $billing->booking->reservation->guest->user->full_name ?? 'N/A' }}</p>
                        <p class="mb-1"><strong>Email:</strong> {{ $billing->booking->reservation->guest->user->email ?? 'N/A' }}</p>
                        <p class="mb-0"><strong>Mobile:</strong> {{ $billing->booking->reservation->guest->mobile_number ?? 'N/A' }}</p>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Add Charge Modal -->
@if($billing->billing_status !== 'paid')
    <div class="modal fade" id="addChargeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #ffc107; color: #333;">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Add Additional Charge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('receptionist.billing.additional-charge.store', $billing) }}" method="POST">
                    @csrf
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-control" required>
                                @foreach(\App\Models\AdditionalCharge::categories() as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" placeholder="e.g., Mini-bar consumption" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" placeholder="0.00" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Optional details..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Charge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endif

<!-- Edit Charge Modals -->
@foreach($billing->additionalCharges as $charge)
    <div class="modal fade" id="editChargeModal{{ $charge->id }}" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header" style="background-color: #ffc107; color: #333;">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> Edit Additional Charge</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="{{ route('receptionist.billing.additional-charge.update', $charge) }}" method="POST">
                    @csrf
                    @method('PUT')
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-control" required>
                                @foreach(\App\Models\AdditionalCharge::categories() as $value => $label)
                                    <option value="{{ $value }}" {{ $charge->category === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control" value="{{ $charge->description }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount <span class="text-danger">*</span></label>
                            <input type="number" step="0.01" min="0.01" name="amount" class="form-control" value="{{ $charge->amount }}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" class="form-control" rows="2">{{ $charge->notes }}</textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Charge</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
@endforeach
@endsection