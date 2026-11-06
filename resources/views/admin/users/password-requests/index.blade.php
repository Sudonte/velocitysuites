@extends('layouts.app')

@section('title', 'Password Reset Requests - Admin')
@section('livePage', '1')

@push('styles')
<style>
    .pr-toolbar .form-label { margin-bottom: .25rem; }
    .pr-toolbar .form-select, .pr-toolbar .form-control { height: calc(2.25rem + 2px); }
    .pr-chip { display: inline-flex; align-items: center; padding: .18rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 700; white-space: nowrap; }
    .pr-chip.pending { background: #fff4de; color: #8a5a00; }
    .pr-chip.approved { background: #dbeafe; color: #1e40af; }
    .pr-chip.rejected { background: #fde7e7; color: #a11; }
    .pr-chip.completed { background: #dcfce7; color: #166534; }
    .pr-chip.expired { background: #e5e7eb; color: #4b5563; }
    .pr-chip.role { background: #eef0f3; color: #2f343c; }
    [data-bs-theme="dark"] .pr-chip.pending { background: #3a2f17; color: #f2c36b; }
    [data-bs-theme="dark"] .pr-chip.approved { background: #1e3a5f; color: #bcd6ff; }
    [data-bs-theme="dark"] .pr-chip.rejected { background: #3d1f1f; color: #ff8a80; }
    [data-bs-theme="dark"] .pr-chip.completed { background: #1d3322; color: #a5d6a7; }
    [data-bs-theme="dark"] .pr-chip.expired { background: #2a2d34; color: #c6c6c6; }
    [data-bs-theme="dark"] .pr-chip.role { background: #2f343c; color: #d5d8dd; }
    .pr-table th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; white-space: nowrap; }
    .pr-table td { vertical-align: middle; }
    .pr-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
    .pr-muted { color: var(--text-light); font-size: .85rem; }
    .pr-card { border: 1px solid var(--border-color); border-radius: 12px; background: var(--surface-color); padding: .9rem 1rem; margin-bottom: .75rem; }
    .pr-card dl { display: grid; grid-template-columns: 6.2rem 1fr; gap: .25rem .75rem; margin: .6rem 0; font-size: .9rem; }
    .pr-card dt { color: var(--text-light); font-weight: 600; }
    .pr-card dd { margin: 0; color: var(--text-dark); overflow-wrap: anywhere; }
    [data-bs-theme="dark"] .pr-actions .btn-success { background: #2e7d32; border-color: #2e7d32; color: #fff; }
    [data-bs-theme="dark"] .pr-actions .btn-outline-danger { color: #ff8a80; border-color: #ff8a80; }
</style>
@endpush

@section('content')
<div class="container-fluid py-4">
    <x-page-header icon="fas fa-key" title="Password Reset Requests" subtitle="Manager and Receptionist accounts awaiting a password reset.">
        <x-slot:actions>
            <a href="{{ route('admin.users.index') }}" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left"></i> Back to User Management
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('temporary_password'))
        @php $tp = session('temporary_password'); @endphp
        <div class="alert alert-warning border-warning" role="alert" id="tempPasswordBox">
            <div class="fw-bold mb-1"><i class="fas fa-key"></i> Temporary password for {{ $tp['name'] }} ({{ $tp['role'] }}, {{ $tp['email'] }})</div>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                <code class="fs-5 px-2 py-1 rounded bg-body-secondary" id="tempPasswordValue" style="user-select: all;">{{ $tp['password'] }}</code>
                <button type="button" class="btn btn-sm btn-outline-dark" id="tempPasswordCopy"><i class="fas fa-copy"></i> Copy</button>
            </div>
            <div class="small">Tell {{ $tp['name'] }} to sign in with it. It works until <strong>{{ $tp['expires'] }}</strong> (24 hours) and must be changed at first login.</div>
        </div>
    @endif

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

    @php
        $roleLabel = fn ($r) => ucfirst($r);
        $filtered = $status !== '' || $role !== '' || $search !== '';
        $empty = $status === 'pending' && $role === '' && $search === '' ? 'No pending requests'
            : ($status === 'approved' && $role === '' && $search === '' ? 'No approved requests'
            : ($filtered ? 'No requests match your filters' : 'No password reset requests'));
    @endphp

    <!-- Filter toolbar: one row on desktop, stacked on phones; a change applies at once -->
    <x-card bodyClass="card-body" class="mb-4">
        <form method="GET" action="{{ route('admin.users.password-requests.index') }}" class="row g-3 align-items-end pr-toolbar" id="prFilters">
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label small text-muted" for="prStatus">Status</label>
                <select name="status" id="prStatus" class="form-select" data-autosubmit>
                    <option value="" {{ $status === '' ? 'selected' : '' }}>All statuses ({{ $counts['all'] }})</option>
                    @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'completed' => 'Completed', 'expired' => 'Expired'] as $value => $label)
                        <option value="{{ $value }}" {{ $status === $value ? 'selected' : '' }}>{{ $label }} ({{ $counts[$value] }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-3">
                <label class="form-label small text-muted" for="prRole">Role</label>
                <select name="role" id="prRole" class="form-select" data-autosubmit>
                    <option value="" {{ $role === '' ? 'selected' : '' }}>All roles</option>
                    <option value="receptionist" {{ $role === 'receptionist' ? 'selected' : '' }}>Receptionist</option>
                    <option value="manager" {{ $role === 'manager' ? 'selected' : '' }}>Manager</option>
                </select>
            </div>
            <div class="col-12 col-md-4 col-lg-4">
                <label class="form-label small text-muted" for="prSearch">Search</label>
                <input type="search" name="search" id="prSearch" class="form-control" placeholder="Name or email" value="{{ $search }}">
            </div>
            <div class="col-12 col-lg-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-fill"><i class="fas fa-search"></i> Search</button>
                @if ($filtered)
                    <a href="{{ route('admin.users.password-requests.index') }}" class="btn btn-outline-secondary" aria-label="Clear filters" title="Clear filters"><i class="fas fa-xmark"></i></a>
                @endif
            </div>
        </form>
    </x-card>

    @if ($requests->isEmpty())
        <x-card bodyClass="card-body">
            <x-empty-state icon="fas fa-key" message="{{ $empty }}" />
        </x-card>
    @else
        <div class="text-muted small fw-semibold mb-2">{{ $requests->total() }} {{ \Illuminate\Support\Str::plural('request', $requests->total()) }}</div>

        {{-- desktop: a table --}}
        <div class="d-none d-md-block">
            <x-card bodyClass="table-responsive">
                <table class="table table-hover align-middle mb-0 pr-table">
                    <thead>
                        <tr><th>Name</th><th>Role</th><th>Email</th><th>Requested</th><th>Status</th><th>Failed logins</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $req)
                            @php $st = $req->status; @endphp
                            <tr>
                                <td><strong>{{ $req->user->full_name }}</strong></td>
                                <td><span class="pr-chip role">{{ $roleLabel($req->user->role) }}</span></td>
                                <td style="white-space:nowrap;">{{ $req->user->email }}</td>
                                <td class="pr-num">{{ $req->created_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') }}</td>
                                <td>
                                    <span class="pr-chip {{ $st }}">{{ ucfirst($st) }}</span>
                                    @if ($req->processedBy && $req->processed_at)
                                        <div class="pr-muted mt-1 pr-num">by {{ $req->processedBy->full_name }} &middot; {{ $req->processed_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') }}</div>
                                    @endif
                                </td>
                                <td class="pr-num">
                                    @if ($req->user->isLoginLocked())
                                        <span class="badge bg-danger">{{ $req->user->failed_login_attempts }} attempts</span>
                                    @else
                                        <span class="pr-muted">{{ $req->user->failed_login_attempts }}</span>
                                    @endif
                                </td>
                                <td class="text-end">@include('admin.users.password-requests.partials.actions', ['req' => $req])</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>

        {{-- phones: one card per request --}}
        <div class="d-md-none">
            @foreach ($requests as $req)
                @php $st = $req->status; @endphp
                <div class="pr-card">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <strong>{{ $req->user->full_name }}</strong>
                        <span class="pr-chip {{ $st }}">{{ ucfirst($st) }}</span>
                    </div>
                    <dl>
                        <dt>Role</dt><dd><span class="pr-chip role">{{ $roleLabel($req->user->role) }}</span></dd>
                        <dt>Email</dt><dd>{{ $req->user->email }}</dd>
                        <dt>Requested</dt><dd class="pr-num">{{ $req->created_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd>
                        @if ($req->processedBy && $req->processed_at)
                            <dt>Handled</dt><dd class="pr-num">by {{ $req->processedBy->full_name }}, {{ $req->processed_at->copy()->timezone('Asia/Manila')->format('M j, Y g:i A') }}</dd>
                        @endif
                        <dt>Failed logins</dt><dd>{{ $req->user->failed_login_attempts }}</dd>
                    </dl>
                    @include('admin.users.password-requests.partials.actions', ['req' => $req])
                </div>
            @endforeach
        </div>
    @endif

    {{-- Reject: one reason dialog per pending request --}}
    @foreach ($requests->where('status', 'pending') as $req)
        <div class="modal fade" id="rejectModal{{ $req->id }}" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <form action="{{ route('admin.users.password-requests.reject', $req) }}" method="POST" class="modal-content">
                    @csrf
                    @method('PUT')
                    <div class="modal-header">
                        <h5 class="modal-title">Reject request - {{ $req->user->full_name }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <label for="reason{{ $req->id }}" class="form-label">Reason</label>
                        <textarea name="reason" id="reason{{ $req->id }}" class="form-control" rows="3" required maxlength="255"></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-danger">Reject request</button>
                    </div>
                </form>
            </div>
        </div>
    @endforeach

    <!-- Pagination (10 per page) -->
    <div class="d-flex justify-content-center mt-4">
        <x-pagination :paginator="$requests" />
    </div>
</div>
@endsection

@push('scripts')
<script>
(function () {
    document.querySelectorAll('#prFilters [data-autosubmit]').forEach(function (el) { el.addEventListener('change', function () { el.form.submit(); }); });
})();
(function () {
    var btn = document.getElementById('tempPasswordCopy'), val = document.getElementById('tempPasswordValue');
    if (!btn || !val) return;
    btn.addEventListener('click', function () {
        function done() { btn.innerHTML = '<i class="fas fa-check"></i> Copied'; setTimeout(function () { btn.innerHTML = '<i class="fas fa-copy"></i> Copy'; }, 2000); }
        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(val.textContent.trim()).then(done); return; }
        var r = document.createRange(); r.selectNodeContents(val); var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r); try { document.execCommand('copy'); done(); } catch (e) { /* the text is selected for a manual copy */ }
    });
})();
</script>
@endpush
