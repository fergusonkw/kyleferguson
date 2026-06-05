<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Generation Needs Attention</title>
</head>
<body style="font-family: sans-serif; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <h2>Invoice Generation Needs Attention</h2>
    <p>Invoice generation for <strong>{{ $business->name }}</strong> ({{ $period }}) could not complete automatically.</p>
    <p><strong>Reason:</strong> {{ $reason }}</p>
    <p>Please log in to the admin panel to resolve the issue and generate the invoice manually.</p>
</body>
</html>
