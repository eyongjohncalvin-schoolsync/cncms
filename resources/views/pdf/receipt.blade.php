@php
    /**
     * Payment receipt — rendered by App\Services\PaymentReceiptService::pdf()
     * strictly from the frozen $r snapshot array (never live data). Header
     * matched to resources/views/pdf/bill.blade.php.
     *
     * @var array $r     the payment_receipts.snapshot array
     * @var ?string $logo  company logo data URI (live branding, not frozen)
     */
    $customer = $r['customer'] ?? [];
    $payment = $r['payment'] ?? [];
    $company = $r['company'] ?? [];
    $periods = $payment['periods'] ?? [];
    $issuedAt = ! empty($r['issued_at']) ? \Illuminate\Support\Carbon::parse($r['issued_at']) : null;

    $periodLabel = function (string $p): string {
        return \Illuminate\Support\Carbon::createFromFormat('!Y-m', $p)->format('M Y');
    };

    if (count($periods) === 0) {
        $periodText = '—';
    } elseif (count($periods) === 1) {
        $periodText = $periodLabel($periods[0]);
    } else {
        $periodText = $periodLabel($periods[0]) . ' – ' . $periodLabel(end($periods));
    }

    $methodLabels = ['monthly' => 'Monthly', 'months' => 'Multi-month prepayment', 'yearly' => 'Yearly prepayment'];
    $methodText = $methodLabels[$payment['method'] ?? ''] ?? ucfirst((string) ($payment['method'] ?? ''));
    $months = (int) ($payment['months'] ?? 0) ?: count($periods);
    $description = 'Cable subscription' . ($months > 1 ? " ({$months} months)" : '');
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $r['receipt_number'] ?? '' }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #111;
            margin: 0;
            padding: 18px;
        }
        .letterhead { width: 100%; margin-bottom: 8px; }
        .letterhead .logo { max-height: 46px; max-width: 130px; }
        .company-name { font-size: 13px; font-weight: bold; }
        .company-meta { font-size: 9px; color: #444; }
        .title {
            font-size: 15px;
            font-weight: bold;
            letter-spacing: 1px;
            margin: 10px 0 2px;
            border-top: 2px solid #111;
            border-bottom: 2px solid #111;
            padding: 4px 0;
            text-align: center;
        }
        table.meta { width: 100%; border-collapse: collapse; margin: 8px 0; }
        table.meta td { padding: 2px 0; vertical-align: top; }
        table.meta td.label { width: 90px; font-weight: bold; }
        table.items { width: 100%; border-collapse: collapse; margin: 10px 0; }
        table.items th, table.items td { border: 1px solid #999; padding: 4px 6px; text-align: left; font-size: 10px; }
        table.items th { background: #eee; }
        table.items td.amount, table.items th.amount { text-align: right; }
        table.items tr.total td { font-weight: bold; font-size: 12px; border-top: 2px solid #111; }
        .footer { margin-top: 14px; font-size: 9px; color: #444; font-style: italic; text-align: center; border-top: 1px solid #ccc; padding-top: 6px; }
        .void { color: #b00; font-weight: bold; }
    </style>
</head>
<body>
    @if ($logo)
        <table class="letterhead"><tr><td><img src="{{ $logo }}" class="logo" alt="logo"></td></tr></table>
    @endif

    <div class="company-name">{{ $company['name'] ?? '' }}</div>
    <div class="company-meta">
        {{ $company['location'] ?? '' }}@if(!empty($company['head_office'])) &middot; {{ $company['head_office'] }}@endif<br>
        @if(!empty($company['tech_number']))Tel: {{ $company['tech_number'] }}<br>@endif
        @if(!empty($company['momo_number']))MOMO: {{ $company['momo_number'] }}@if(!empty($company['momo_name'])) ({{ $company['momo_name'] }})@endif @endif
    </div>

    <div class="title">PAYMENT RECEIPT</div>

    <table class="meta">
        <tr>
            <td class="label">Receipt No:</td>
            <td>{{ $r['receipt_number'] ?? '' }}</td>
        </tr>
        <tr>
            <td class="label">Issued:</td>
            <td>{{ $issuedAt?->format('d M Y, H:i') }}</td>
        </tr>
        <tr>
            <td class="label">Received from:</td>
            <td>
                {{ $customer['name'] ?? '' }}
                @if(!empty($customer['phone'])) &middot; {{ $customer['phone'] }}@endif
            </td>
        </tr>
        <tr>
            <td class="label">Zone:</td>
            <td>{{ $customer['zone'] ?? '—' }}@if(!empty($customer['branch'])) ({{ $customer['branch'] }})@endif</td>
        </tr>
        @if(!empty($customer['code']))
        <tr>
            <td class="label">Account:</td>
            <td>{{ $customer['code'] }}</td>
        </tr>
        @endif
        @if(!empty($payment['momo_ref']))
        <tr>
            <td class="label">MOMO Ref:</td>
            <td>{{ $payment['momo_ref'] }}</td>
        </tr>
        @endif
    </table>

    <table class="items">
        <tr>
            <th>Description</th>
            <th>Period(s)</th>
            <th>Method</th>
            <th class="amount">Amount (FCFA)</th>
        </tr>
        <tr>
            <td>{{ $description }}</td>
            <td>{{ $periodText }}</td>
            <td>{{ $methodText }}</td>
            <td class="amount">{{ number_format((float) ($payment['amount'] ?? $r['amount'] ?? 0), 2) }}</td>
        </tr>
        <tr class="total">
            <td colspan="3">TOTAL PAID</td>
            <td class="amount">{{ number_format((float) ($r['amount'] ?? 0), 2) }}</td>
        </tr>
    </table>

    @if(!empty($payment['expiration_date']))
        <div class="company-meta">Prepaid cover through: {{ \Illuminate\Support\Carbon::parse($payment['expiration_date'])->format('d M Y') }}</div>
    @endif

    <div class="footer">
        This is a computer-generated receipt.
        @if(!empty($company['rccm_number'])) &middot; RCCM: {{ $company['rccm_number'] }}@endif
        @if(!empty($company['niu'])) &middot; NIU: {{ $company['niu'] }}@endif
    </div>
</body>
</html>
