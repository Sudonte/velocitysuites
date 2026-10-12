{{--
    Optional "Guest account" lookup for the receptionist's Create Reservation,
    Create Booking and Walk-in Check-in forms (App\Support\GuestAccountLink).
    Picking an account posts guest_user_id and fills the guest name fields;
    leaving it empty keeps the typed-name flow.
--}}
@php $linked = old('guest_user_id') ? \App\Models\User::where('role', 'guest')->find(old('guest_user_id')) : null; @endphp
<div class="guest-account-field mb-3" data-search-url="{{ route('receptionist.guest-accounts.search') }}">
    <label for="guestAccountSearch" class="form-label">Guest account <small class="text-muted">(optional)</small></label>
    <input type="hidden" name="guest_user_id" value="{{ $linked?->id }}">
    <div class="position-relative {{ $linked ? 'd-none' : '' }}" data-ga-search>
        <input type="search" id="guestAccountSearch" class="form-control" placeholder="Type the guest's email or name" autocomplete="off">
        <div class="list-group position-absolute w-100 shadow-sm d-none" style="z-index: 20;" data-ga-results></div>
    </div>
    <div class="alert alert-info py-2 mb-0 d-flex justify-content-between align-items-center {{ $linked ? '' : 'd-none' }}" data-ga-linked>
        <span><i class="fas fa-user-check"></i> Linked to <strong data-ga-name>{{ $linked?->full_name }}</strong> <small data-ga-email>{{ $linked?->email }}</small></span>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-ga-clear>Remove</button>
    </div>
    <small class="text-muted d-block mt-1">Link an existing guest account and this stay appears on the guest's website and app. Leave empty for a guest without an account.</small>
    @error('guest_user_id')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
</div>

@once
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.guest-account-field').forEach(function (box) {
        const form = box.closest('form');
        const hidden = box.querySelector('input[name="guest_user_id"]');
        const search = box.querySelector('#guestAccountSearch');
        const results = box.querySelector('[data-ga-results]');
        const searchWrap = box.querySelector('[data-ga-search]');
        const linked = box.querySelector('[data-ga-linked]');
        let timer = null;

        const fill = function (name, value) {
            const input = form && form.querySelector('[name="' + name + '"]');
            if (input && value != null) { input.value = value; input.dispatchEvent(new Event('input', { bubbles: true })); }
        };

        function pick(acc) {
            hidden.value = acc.id;
            fill('guest_first_name', acc.first_name);
            fill('guest_middle_name', acc.middle_name || '');
            fill('guest_last_name', acc.last_name);
            box.querySelector('[data-ga-name]').textContent = acc.name;
            box.querySelector('[data-ga-email]').textContent = acc.email;
            results.classList.add('d-none');
            searchWrap.classList.add('d-none');
            linked.classList.remove('d-none');
        }

        box.querySelector('[data-ga-clear]').addEventListener('click', function () {
            hidden.value = '';
            search.value = '';
            linked.classList.add('d-none');
            searchWrap.classList.remove('d-none');
            search.focus();
        });

        search.addEventListener('input', function () {
            clearTimeout(timer);
            const q = search.value.trim();
            if (q.length < 2) { results.classList.add('d-none'); return; }
            timer = setTimeout(function () {
                fetch(box.dataset.searchUrl + '?q=' + encodeURIComponent(q), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } })
                    .then((r) => r.ok ? r.json() : [])
                    .then(function (list) {
                        results.innerHTML = '';
                        if (!list.length) {
                            results.innerHTML = '<div class="list-group-item small text-muted">No guest account found - continue without one.</div>';
                        }
                        list.forEach(function (acc) {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'list-group-item list-group-item-action';
                            item.innerHTML = '<strong></strong><small class="d-block text-muted"></small>';
                            item.querySelector('strong').textContent = acc.name;
                            item.querySelector('small').textContent = acc.email + (acc.mobile ? ' - ' + acc.mobile : '');
                            item.addEventListener('click', function () { pick(acc); });
                            results.appendChild(item);
                        });
                        results.classList.remove('d-none');
                    });
            }, 250);
        });
        document.addEventListener('click', function (e) { if (!box.contains(e.target)) results.classList.add('d-none'); });
    });
});
</script>
@endpush
@endonce
