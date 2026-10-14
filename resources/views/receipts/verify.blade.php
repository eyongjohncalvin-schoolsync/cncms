<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Receipt {{ $receiptNumber }} — {{ $isVoid ? 'Void' : 'Genuine' }}</title>
    <style>
        :root { color-scheme: light; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: #0f172a; padding: 16px; box-sizing: border-box; }
        .card { width: 100%; max-width: 380px; background: #fff; border-radius: 16px; box-shadow: 0 10px 30px rgba(15, 23, 42, .08); overflow: hidden; }
        .status { padding: 22px 20px; text-align: center; color: #fff; }
        .status.ok { background: #15803d; }
        .status.void { background: #b91c1c; }
        .status .icon { font-size: 40px; line-height: 1; }
        .status h1 { margin: 8px 0 2px; font-size: 20px; }
        .status p { margin: 0; opacity: .9; font-size: 14px; }
        dl { margin: 0; padding: 16px 20px 20px; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 14px; }
        dt { color: #64748b; }
        dd { margin: 0; text-align: right; font-weight: 600; }
        .foot { padding: 0 20px 18px; font-size: 12px; color: #64748b; text-align: center; }
    </style>
</head>
<body>
    <main class="card">
        @if ($isVoid)
            <div class="status void">
                <div class="icon">✕</div>
                <h1>Receipt cancelled (VOID)</h1>
                <p>This receipt is no longer valid. Please contact {{ $companyName ?? 'the company' }}.</p>
            </div>
        @else
            <div class="status ok">
                <div class="icon">✓</div>
                <h1>Genuine receipt</h1>
                <p>Issued by {{ $companyName ?? 'the company' }}.</p>
            </div>
        @endif
        <dl>
            <dt>Receipt</dt><dd>{{ $receiptNumber }}</dd>
            @if ($customerName)<dt>Customer</dt><dd>{{ $customerName }}</dd>@endif
            <dt>Amount</dt><dd>{{ number_format((float) $amount, 0, '.', ',') }} FCFA</dd>
            @if ($issuedAt)<dt>Issued</dt><dd>{{ $issuedAt->format('d M Y, H:i') }}</dd>@endif
        </dl>
        <div class="foot">Check that these details match the paper receipt.</div>
    </main>
</body>
</html>
