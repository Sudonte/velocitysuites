<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We couldn't find that page - Velocity Suites</title>
    <link rel="icon" href="{{ asset('images/logo.jpg') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<main class="container text-center py-5" style="max-width:560px">
    <img src="{{ asset('images/logo.jpg') }}" alt="Velocity Suites" width="72" class="rounded mb-3">
    <h1 class="h4 fw-bold">We couldn&rsquo;t find that page</h1>
    <p class="text-muted">It may have been removed, or the link is not right.</p>
    <div class="d-flex flex-wrap justify-content-center gap-2">
        <a class="btn btn-danger" href="{{ url('/') }}">Home</a>
        <a class="btn btn-outline-danger" href="{{ url('/rooms') }}">Browse rooms</a>
        @auth<a class="btn btn-outline-secondary" href="{{ url('/dashboard') }}">My dashboard</a>@endauth
    </div>
</main>
</body>
</html>
