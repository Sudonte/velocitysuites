<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    @include('reports.partials.pdf-styles')
</head>
<body>
    @include('reports.partials.pdf-header', [
        'reportTitle' => 'Manager Report',
        'generatedAt' => $generatedAt,
        'periodLabel' => $periodLabel,
    ])

    @include('reports.partials.pdf-body', ['report' => $report, 'withRevenue' => false, 'periodLabel' => $periodLabel])

    <p class="footer-note">Velocity Suites &middot; Confidential internal report &middot; Generated {{ $generatedAt->format('M d, Y h:i A') }}</p>
</body>
</html>
