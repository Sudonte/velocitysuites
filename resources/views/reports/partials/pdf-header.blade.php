{{-- Shared masthead for every Admin/Manager PDF report - expects
     $reportTitle, $generatedAt (Carbon), and an optional $periodLabel
     (null means "all-time"). --}}
<div class="pdf-header">
    <table>
        <tr>
            <td class="logo-cell">
                <img src="{{ public_path('images/logo.jpg') }}" alt="Velocity Suites">
            </td>
            <td>
                <p class="brand-name">Velocity Suites</p>
                <p class="report-title">{{ $reportTitle }}</p>
            </td>
            <td class="meta">
                Generated: {{ $generatedAt->format('M d, Y h:i A') }}<br>
                Period: {{ $periodLabel ?? 'All-time' }}
            </td>
        </tr>
    </table>
</div>
