<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ 'VelocitySuites-Receipt'.($receipt['receipt_stage'] ?? '').'-'.$receipt['receipt_number'] }}</title>
</head>
<body style="margin:0">
    @include('portal.receipt-doc', ['receipt' => $receipt, 'mode' => 'pdf'])
</body>
</html>
