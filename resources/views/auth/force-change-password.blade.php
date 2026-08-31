@extends('layouts.app')

@section('title', 'Set a New Password')

@section('content')
<div class="container py-5" style="max-width: 480px;">
    <x-card title="Set a New Password" icon="fas fa-lock" bodyClass="card-body">
        <p class="text-muted">
            Your account is still using the default password. For security, please set your own
            permanent password before continuing to the dashboard.
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
                           id="password" name="password" minlength="8" required>
                    <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                </div>
                @error('password')
                    <div class="invalid-feedback d-block">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group mb-3">
                <label for="password_confirmation">Confirm New Password *</label>
                <div class="password-input-wrapper">
                    <input type="password" class="form-control" id="password_confirmation" name="password_confirmation" minlength="8" required>
                    <button type="button" class="password-toggle-icon toggle-password" aria-label="Show password" title="Show password"><i class="fas fa-eye-slash"></i></button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-100">
                <i class="fas fa-save"></i> Set Password & Continue
            </button>
        </form>
    </x-card>
</div>
@endsection
