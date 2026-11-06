@extends('layouts.app')

@section('title', 'Set a New Password')

@section('content')
<div class="container py-5" style="max-width: 480px;">
    <x-card title="Set a New Password" icon="fas fa-lock" bodyClass="card-body">
        <p class="text-muted">
            You signed in with a temporary password. For security, set your own permanent password before continuing to your dashboard.
        </p>

        @if ($errors->any())
            <div class="alert alert-danger">
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form action="{{ route('force-password-change.update') }}" method="POST">
            @csrf

            <div class="form-group mb-3">
                <label for="password">New Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" class="form-control @error('password') is-invalid @enderror"
                           id="password" name="password" minlength="8" pattern="(?=.*[A-Za-z])(?=.*[0-9]).{8,}" title="At least 8 characters with a letter and a number" required>
                    <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                </div>
                @error('password')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>
            <ul class="list-unstyled small mb-3" id="pwRules" aria-live="polite">
                <li data-k="len"><i class="far fa-circle me-1"></i>At least 8 characters</li>
                <li data-k="letter"><i class="far fa-circle me-1"></i>A letter</li>
                <li data-k="num"><i class="far fa-circle me-1"></i>A number</li>
            </ul>

            <div class="form-group mb-3">
                <label for="password_confirmation">Confirm New Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required>
                    <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                </div>
            </div>

            <div class="small mb-3" id="pwMatch" aria-live="polite"></div>
            <button type="submit" class="btn btn-primary w-100" id="pwSave" disabled>
                <i class="fas fa-save"></i> Set Password &amp; Continue
            </button>
        </form>
    </x-card>
</div>
@endsection

@push('scripts')
<script>
(function () {
    var pw = document.getElementById('password'), cf = document.getElementById('password_confirmation'), save = document.getElementById('pwSave'), match = document.getElementById('pwMatch');
    function rules(v) { return { len: v.length >= 8, letter: /[A-Za-z]/.test(v), num: /\d/.test(v) }; }
    function update() {
        var r = rules(pw.value), notTemp = pw.value !== 'velocitysuites123', ok = r.len && r.letter && r.num && notTemp;
        document.querySelectorAll('#pwRules li').forEach(function (li) { var on = r[li.dataset.k]; li.className = on ? 'text-success' : 'text-muted'; li.querySelector('i').className = (on ? 'fas fa-circle-check' : 'far fa-circle') + ' me-1'; });
        var same = cf.value !== '' && pw.value === cf.value;
        if (!notTemp) { match.innerHTML = '<span class="text-danger">Choose a new password that is different from the temporary one.</span>'; save.disabled = true; return; }
        match.innerHTML = cf.value === '' ? '' : same ? '<span class="text-success">The passwords match</span>' : '<span class="text-danger">The passwords do not match</span>';
        save.disabled = !(ok && same);
    }
    pw.addEventListener('input', update); cf.addEventListener('input', update); update();
})();
</script>
@endpush
