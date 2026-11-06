{{-- Every emailed code (sign-up, password reset, email change ...): table layout with inline styles so Gmail (desktop, phone, light and dark) draws it the same --}}
@php
    $base = rtrim(config('app.url'), '/');
    $logo = $base . '/images/logo.jpg';
    $hotelEmail = 'velocitysuites@gmail.com';
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $subject }}</title>
    <style>
        @media (prefers-color-scheme: dark) {
            .vs-bg { background-color: #14161b !important; }
            .vs-card { background-color: #1e2128 !important; }
            .vs-text { color: #ededed !important; }
            .vs-muted { color: #b4b6bd !important; }
            .vs-code-box { background-color: #3d1f1f !important; border-color: #7a3a3f !important; }
            .vs-code { color: #ffb3b8 !important; }
            .vs-rule { border-color: #34383f !important; }
            .vs-link { color: #ff9aa1 !important; }
        }
        @media only screen and (max-width: 480px) {
            .vs-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .vs-code { font-size: 32px !important; letter-spacing: 8px !important; }
        }
    </style>
</head>
<body class="vs-bg" style="margin:0;padding:0;background-color:#f4f4f6;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Your code is {{ $code }}. It expires in {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}.</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="vs-bg" style="background-color:#f4f4f6;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="vs-card" style="max-width:520px;background-color:#ffffff;border-radius:14px;border:1px solid #e7e7ea;">
                <tr>
                    <td align="center" class="vs-pad" style="padding:28px 32px 8px 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                            <tr>
                                <td align="center" style="background-color:#ffffff;border:2px solid #C7333E;border-radius:14px;padding:6px;">
                                    <img src="{{ $logo }}" alt="Velocity Suites" width="64" height="73" style="display:block;width:64px;height:73px;border:0;outline:none;text-decoration:none;">
                                </td>
                            </tr>
                        </table>
                        <div style="font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:26px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#C7333E;margin-top:12px;">Velocity Suites</div>
                        <div class="vs-muted" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;color:#5f6368;margin-top:2px;">Your comfort is our service</div>
                    </td>
                </tr>
                <tr>
                    <td class="vs-pad" style="padding:20px 32px 8px 32px;font-family:Arial,Helvetica,sans-serif;">
                        <p class="vs-text" style="margin:0 0 12px 0;font-size:16px;line-height:24px;color:#1b1b1f;">Hi {{ $name }},</p>
                        <p class="vs-text" style="margin:0;font-size:16px;line-height:24px;color:#1b1b1f;">{{ $intro }}</p>
                    </td>
                </tr>
                <tr>
                    <td align="center" class="vs-pad" style="padding:12px 32px;">
                        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 auto;">
                            <tr>
                                <td align="center" class="vs-code-box" style="background-color:#fdecec;border:1px solid #f3c6c9;border-radius:12px;padding:16px 28px;">
                                    <span class="vs-code" style="font-family:'Courier New',Courier,monospace;font-size:38px;line-height:44px;font-weight:700;letter-spacing:12px;color:#A62B34;">{{ $code }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td class="vs-pad" style="padding:8px 32px 24px 32px;font-family:Arial,Helvetica,sans-serif;">
                        <p class="vs-text" style="margin:0 0 12px 0;font-size:16px;line-height:24px;font-weight:700;color:#1b1b1f;text-align:center;">This code expires in {{ $minutes }} {{ $minutes === 1 ? 'minute' : 'minutes' }}.</p>
                        <p class="vs-muted" style="margin:0;font-size:14px;line-height:21px;color:#5f6368;">{{ $ignore }}</p>
                    </td>
                </tr>
                <tr>
                    <td class="vs-pad vs-rule" style="padding:16px 32px 24px 32px;border-top:1px solid #e7e7ea;font-family:Arial,Helvetica,sans-serif;text-align:center;">
                        <p class="vs-muted" style="margin:0 0 4px 0;font-size:13px;line-height:19px;color:#5f6368;">Questions? <a href="mailto:{{ $hotelEmail }}" class="vs-link" style="color:#A62B34;text-decoration:underline;">{{ $hotelEmail }}</a></p>
                        <p class="vs-muted" style="margin:0;font-size:12px;line-height:18px;color:#5f6368;">&copy; 2026 Velocity Suites</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
