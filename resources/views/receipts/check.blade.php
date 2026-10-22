@php
    /**
     * Public receipt check page — App\Http\Controllers\ReceiptCheckController
     * (docs/plans/cash-controls.md). One screen a customer reads on a phone:
     * a coloured verdict, the recorded facts to compare with their paper, and
     * a box to type the security code when the QR didn't carry it.
     *
     * @var string $outcome  genuine|check_amount|mismatch|void|unreported|cancelled|unknown
     */
    $verdicts = [
        'genuine' => ['ok', '✓', 'Genuine receipt', 'Reçu authentique', 'This receipt was issued and recorded by '.($companyName ?? 'the company').'.'],
        'check_amount' => ['ok', '✓', 'Receipt recorded', 'Reçu enregistré', 'Check that the amount below is the amount written on your receipt.'],
        'mismatch' => ['bad', '!', 'Details do not match', 'Les détails ne correspondent pas', 'The security code does not match what was recorded. The receipt may have been altered — please call us.'],
        'void' => ['bad', '✕', 'Receipt cancelled (VOID)', 'Reçu annulé', 'This receipt is no longer valid. Please call us.'],
        'unreported' => ['warn', '…', 'Not recorded yet', 'Pas encore enregistré', 'This number was issued to one of our collectors, but no payment has been recorded on it yet. Collectors working offline record within a day — if this page still says so tomorrow, please call us.'],
        'cancelled' => ['bad', '✕', 'Cancelled number', 'Numéro annulé', 'This receipt number was cancelled before use. It should not carry a payment — please call us.'],
        'unknown' => ['bad', '✕', 'Not one of our receipts', 'Reçu inconnu', 'We never issued this number. Please call us before paying anyone with this receipt.'],
    ];
    [$tone, $icon, $title, $titleFr, $message] = $verdicts[$outcome];
    $money = fn ($value): string => number_format((float) $value, 0, '.', ' ').' FCFA';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Receipt {{ $number }} — {{ $title }}</title>
    <style>
        :root {
            --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0;
            --ok: #15803d; --warn: #b45309; --bad: #b91c1c; --field: #ffffff;
        }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1220; --card: #111a2e; --text: #e2e8f0; --muted: #94a3b8; --line: #1e293b; --field: #0b1220; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; padding: 16px; }
        .card { width: 100%; max-width: 400px; background: var(--card); border-radius: 16px; box-shadow: 0 10px 30px rgba(15, 23, 42, .12); overflow: hidden; }
        .status { padding: 22px 20px; text-align: center; color: #fff; }
        .status.ok { background: var(--ok); } .status.warn { background: var(--warn); } .status.bad { background: var(--bad); }
        .icon { font-size: 38px; line-height: 1; font-weight: 700; }
        h1 { margin: 8px 0 0; font-size: 20px; }
        .fr { margin: 2px 0 8px; font-size: 13px; opacity: .85; font-style: italic; }
        .status p { margin: 0; font-size: 14px; line-height: 1.45; }
        dl { margin: 0; padding: 16px 20px; display: grid; grid-template-columns: auto 1fr; gap: 8px 16px; font-size: 14px; border-bottom: 1px solid var(--line); }
        dt { color: var(--muted); } dd { margin: 0; text-align: right; font-weight: 600; overflow-wrap: anywhere; }
        .amount { font-size: 18px; }
        form { padding: 16px 20px 4px; display: flex; gap: 8px; }
        input { flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--field); color: var(--text); font-size: 16px; letter-spacing: 2px; text-transform: uppercase; }
        button { padding: 10px 14px; border: 0; border-radius: 10px; background: #1d4ed8; color: #fff; font-size: 15px; font-weight: 600; }
        .hint { padding: 0 20px 4px; font-size: 12px; color: var(--muted); }
        .foot { padding: 12px 20px 18px; font-size: 13px; color: var(--muted); text-align: center; }
        .foot a { color: inherit; font-weight: 600; }
    </style>
</head>
<body>
    <main class="card">
        <div class="status {{ $tone }}">
            <div class="icon" aria-hidden="true">{{ $icon }}</div>
            <h1>{{ $title }}</h1>
            <div class="fr">{{ $titleFr }}</div>
            <p>{{ $message }}</p>
        </div>

        <dl>
            <dt>Receipt</dt><dd>{{ $number }}</dd>
            @if ($receipt)
                @if ($receipt['customer'])<dt>Customer</dt><dd>{{ $receipt['customer'] }}</dd>@endif
                <dt>Amount</dt><dd class="amount">{{ $money($receipt['amount']) }}</dd>
                @if ($receipt['paid_by'])<dt>Paid by</dt><dd>{{ $receipt['paid_by'] }}</dd>@endif
                @if ($receipt['issued_at'])<dt>Issued</dt><dd>{{ $receipt['issued_at']->format('d M Y, H:i') }}</dd>@endif
                @if ($receipt['collected_by'])<dt>Collected by</dt><dd>{{ $receipt['collected_by'] }}</dd>@endif
            @elseif (! empty($holderName))
                <dt>Collector</dt><dd>{{ $holderName }}</dd>
            @endif
        </dl>

        @if (in_array($outcome, ['check_amount', 'mismatch', 'unreported'], true))
            <form method="get" action="">
                <input name="c" value="{{ $code }}" placeholder="XXXX-XXXX" maxlength="12" autocomplete="off" aria-label="Security code">
                <button type="submit">Check</button>
            </form>
            <div class="hint">Type the 8-character security code printed under the QR on your receipt.</div>
        @endif

        <div class="foot">
            {{ $companyName ?? '' }}
            @if ($companyPhone) · <a href="tel:{{ preg_replace('/[^0-9+]/', '', explode('/', $companyPhone)[0]) }}">{{ $companyPhone }}</a>@endif
        </div>
    </main>
</body>
</html>
