@php
    /**
     * Payment receipt for 58 mm / 80 mm roll printers (pocket Bluetooth
     * printers field agents carry) — rendered by
     * App\Services\PaymentReceiptService::pdf() from the same frozen $r
     * snapshot as the A6 receipt (pdf/receipt.blade.php), as one narrow
     * column: no tables wider than the roll, small type, centred header.
     *
     * @var array $r          the payment_receipts.snapshot array
     * @var ?string $logo     company logo data URI (live branding)
     * @var ?string $qr       QR code (PNG data URI) → public verify page
     * @var bool $duplicate   a staff reprint — stamped DUPLICATE
     * @var int $widthMm      58 or 80
     */
    $customer = $r['customer'] ?? [];
    $payment = $r['payment'] ?? [];
    $company = $r['company'] ?? [];
    $periods = $payment['periods'] ?? [];
    $balance = $r['balance'] ?? null;
    $isPrepayment = in_array($payment['method'] ?? '', ['months', 'yearly'], true);
    $issuedAt = ! empty($r['issued_at']) ? \Illuminate\Support\Carbon::parse($r['issued_at']) : null;
    $narrow = $widthMm <= 58;

    $periodLabel = fn (string $p): string => \Illuminate\Support\Carbon::createFromFormat('!Y-m', $p)->format('M Y');
    $periodText = match (true) {
        count($periods) === 0 => '—',
        count($periods) === 1 => $periodLabel($periods[0]),
        default => $periodLabel($periods[0]).' – '.$periodLabel(end($periods)),
    };
    $months = (int) ($payment['months'] ?? 0) ?: count($periods);
    $money = fn ($value): string => number_format((float) $value, 0, '.', ',').' FCFA';
    $code = ! empty($r['security_code']) ? \App\Support\ReceiptSecurityCode::format($r['security_code']) : null;
    $words = $r['amount_in_words'] ?? null;
    // Older snapshots carry no purpose/title: they are subscription receipts.
    $purpose = $r['purpose'] ?? 'subscription';
    $title = $r['title'] ?? 'PAYMENT RECEIPT';
    $installation = $r['installation'] ?? null;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $r['receipt_number'] ?? '' }}</title>
    <style>
        @page { margin: 0; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: {{ $narrow ? 7.5 : 9 }}pt;
            color: #000;
            margin: 0;
            padding: {{ $narrow ? '3mm 3mm' : '4mm 4mm' }};
        }
        .center { text-align: center; }
        .logo { max-height: {{ $narrow ? 28 : 36 }}px; max-width: 80%; }
        .company { font-weight: bold; font-size: {{ $narrow ? 9 : 11 }}pt; }
        .muted { font-size: {{ $narrow ? 6.5 : 7.5 }}pt; }
        .rule { border-top: 1px dashed #000; margin: 4px 0; }
        .title { font-weight: bold; letter-spacing: 1px; margin: 3px 0; }
        .duplicate { border: 1.5px dashed #000; font-weight: bold; letter-spacing: 3px; padding: 1px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        td.k { width: 40%; }
        td.v { text-align: right; }
        .total td { font-weight: bold; font-size: {{ $narrow ? 9 : 11 }}pt; padding-top: 3px; }
        .code { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; letter-spacing: 1px; }
        .words { font-size: {{ $narrow ? 6.5 : 7.5 }}pt; font-style: italic; margin-top: 2px; }
        .qr { width: {{ $narrow ? 80 : 100 }}px; height: {{ $narrow ? 80 : 100 }}px; }
    </style>
</head>
<body>
    @if (! empty($duplicate))
        <div class="center duplicate">DUPLICATE</div>
    @endif

    <div class="center">
        @if ($logo)
            <img src="{{ $logo }}" class="logo" alt="logo"><br>
        @endif
        <div class="company">{{ $company['name'] ?? '' }}</div>
        <div class="muted">
            {{ $company['location'] ?? '' }}<br>
            @if(!empty($company['tech_number']))Tel: {{ $company['tech_number'] }}<br>@endif
            @if(!empty($company['momo_number']))MOMO: {{ $company['momo_number'] }}@endif
        </div>
        <div class="rule"></div>
        <div class="title">{{ $title }}</div>
        <div>{{ $r['receipt_number'] ?? '' }}</div>
        @if($code)<div class="code">Code {{ $code }}</div>@endif
        <div class="muted">{{ $issuedAt?->format('d M Y, H:i') }}</div>
    </div>

    <div class="rule"></div>

    <table>
        <tr><td class="k">Customer</td><td class="v">{{ $customer['name'] ?? '' }}</td></tr>
        @if(!empty($customer['code']))<tr><td class="k">Account</td><td class="v">{{ $customer['code'] }}</td></tr>@endif
        @if(!empty($customer['zone']))<tr><td class="k">Zone</td><td class="v">{{ $customer['zone'] }}</td></tr>@endif
        @if($purpose === 'subscription')
            <tr><td class="k">Period</td><td class="v">{{ $periodText }}</td></tr>
        @else
            <tr><td class="k">For</td><td class="v">{{ $r['description'] ?? ucfirst(str_replace('_', ' ', $purpose)) }}</td></tr>
        @endif
        @if(!empty($r['invoice']['number']))<tr><td class="k">For bill</td><td class="v">{{ $r['invoice']['number'] }}</td></tr>@endif
        @if($isPrepayment)<tr><td class="k">Prepaid</td><td class="v">{{ $months }} month(s)</td></tr>@endif
        @if(!empty($payment['paid_by_label']))<tr><td class="k">Paid by</td><td class="v">{{ $payment['paid_by_label'] }}</td></tr>@endif
        @if(!empty($payment['method_reference']))<tr><td class="k">Txn ID</td><td class="v">{{ $payment['method_reference'] }}</td></tr>@endif
        @if(!empty($payment['momo_ref']))<tr><td class="k">MOMO ref</td><td class="v">{{ $payment['momo_ref'] }}</td></tr>@endif
        @if(!empty($payment['collected_by']))<tr><td class="k">Collected by</td><td class="v">{{ $payment['collected_by'] }}</td></tr>@endif
    </table>

    <div class="rule"></div>

    <table>
        <tr class="total"><td class="k">PAID</td><td class="v">{{ $money($r['amount'] ?? 0) }}</td></tr>
        @if($installation)
            <tr><td class="k">Install fee</td><td class="v">{{ $money($installation['fee']) }}</td></tr>
            <tr><td class="k">Paid so far</td><td class="v">{{ $money($installation['paid']) }}</td></tr>
            <tr><td class="k">Balance</td><td class="v">{{ (float) $installation['balance'] > 0 ? $money($installation['balance']) : (($installation['status'] ?? '') === 'waived' ? 'Rest waived' : 'Fully paid') }}</td></tr>
            @foreach(($installation['free'] ?? []) as $window)
                <tr><td class="k">Free {{ $window['service'] }}</td><td class="v">{{ $window['from_label'] }}–{{ $window['until_label'] }}</td></tr>
            @endforeach
            @foreach(($installation['waiting'] ?? []) as $waiting)
                <tr><td class="k">Free {{ $waiting['service'] }}</td><td class="v">{{ $waiting['months'] }} mo {{ $waiting['status'] === 'pending_approval' ? '(approval)' : 'after fee' }}</td></tr>
            @endforeach
            @if(!empty($installation['paid_until_label']))
                <tr><td class="k">Paid until</td><td class="v">{{ $installation['paid_until_label'] }}</td></tr>
            @endif
            @if(!empty($installation['first_bill_label']))
                <tr><td class="k">First bill</td><td class="v">{{ $installation['first_bill_label'] }}</td></tr>
            @endif
        @endif
        @if($balance)
            @if((float) $balance['remaining'] > 0)
                <tr><td class="k">Still due</td><td class="v">{{ $money($balance['remaining']) }}</td></tr>
            @elseif(! $isPrepayment && (float) $balance['credit'] > 0)
                <tr><td class="k">Credit</td><td class="v">{{ $money($balance['credit']) }}</td></tr>
            @else
                <tr><td class="k">Balance</td><td class="v">Paid up</td></tr>
            @endif
        @endif
    </table>
    @if($words)
        <div class="words">{{ $words['en'] ?? '' }}<br>{{ $words['fr'] ?? '' }}</div>
    @endif

    <div class="rule"></div>

    @if(!empty($qr))
        <div class="center">
            <img src="{{ $qr }}" class="qr" alt="QR code"><br>
            <span class="muted">Scan to check this receipt is genuine</span>
            @if($code)<br><span class="muted">Security code <span class="code">{{ $code }}</span></span>@endif
            <br><span class="muted">Only receipts with a QR code are valid.</span>
        </div>
    @endif

    <div class="center muted" style="margin-top: 4px;">
        Thank you!
        @if(!empty($company['rccm_number']))<br>RCCM: {{ $company['rccm_number'] }}@endif
        @if(!empty($company['niu']))<br>NIU: {{ $company['niu'] }}@endif
    </div>
</body>
</html>
