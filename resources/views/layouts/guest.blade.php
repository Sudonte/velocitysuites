<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    <link rel="icon" href="{{ asset('images/logo.jpg') }}">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <style>
        .auth-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
            padding: 1.5rem;
        }
        .auth-card {
            background: var(--secondary-color);
            border-radius: var(--radius-card);
            padding: 2rem;
            box-shadow: var(--shadow-md);
            width: 100%;
            max-width: 450px;
        }
        .auth-card .logo {
            text-align: center;
            margin-bottom: 2rem;
        }
        .auth-card .logo h1 {
            color: var(--primary-color);
            font-size: 2rem;
        }
        .auth-card .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(193, 18, 31, 0.25);
        }
        .auth-card .btn-primary {
            border-radius: var(--radius-btn);
        }
    </style>
    @stack('styles')
</head>
<body>
    <div class="auth-container">
        @yield('content')
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    @stack('scripts')
</body>
</html>
