@php
    /**
     * Statement of account — rendered by
     * App\Http\Controllers\CustomerStatementController from
     * App\Services\InvoiceService::statement(). Balances: positive = owes,
     * negative = in credit.
     *
     * @var \App\Models\Customer $customer
     * @var ?\App\Models\Company $company
     * @var ?string $logo
     * @var string $accountCode
     * @var string $from
     * @var string $to
     * @var string $opening_balance
     * @var string $closing_balance
     * @var list<array<string, mixed>> $bills
     * @var list<array<string, mixed>> $payments
     * @var string $total_billed
     * @var string $total_paid
     */
    $month = fn (string $p): string => \Illuminate\Support\Carbon::createFromFormat('!Y-m', $p)->format('M Y');
    $money = fn ($v): string => number_format((float) $v, 0, '.', ',');
    $balance = fn ($v): string => (float) $v < 0 ? $money(abs((float) $v)).' CR' : $money($v);
    $statusLabels = ['paid' => 'Paid', 'partly_paid' => 'Part paid', 'unpaid' => 'Unpaid', 'nothing_due' => 'Nothing due'];
    $methodLabels = ['monthly' => 'Monthly', 'months' => 'Prepaid (months)', 'yearly' => 'Prepaid (year)'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Statement {{ $accountCode }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #111; margin: 0; padding: 26px 30px; }
        .head { width: 100%; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
        .head td { vertical-align: top; }
        .logo { max-height: 46px; max-width: 120px; }
        .company { font-size: 14px; font-weight: bold; }
        .muted { color: #555; font-size: 9px; }
        h1 { font-size: 16px; letter-spacing: 1px; margin: 0; text-align: right; }
        .box { width: 100%; margin-bottom: 12px; }
        .box td { vertical-align: top; width: 50%; }
        .label { color: #555; }
        .summary { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .summary td { border: 1px solid #bbb; padding: 6px 8px; text-align: center; }
        .summary .v { font-size: 13px; font-weight: bold; display: block; margin-top: 2px; }
        h2 { font-size: 11px; margin: 14px 0 4px; text-transform: uppercase; letter-spacing: .5px; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th, table.list td { border-bottom: 1px solid #ddd; padding: 4px 5px; text-align: left; }
        table.list th { background: #f1f1f1; font-size: 9px; text-transform: uppercase; }
        .r { text-align: right !important; }
        .paid { color: #15803d; font-weight: bold; }
        .partly_paid { color: #b45309; font-weight: bold; }
        .unpaid { color: #b91c1c; font-weight: bold; }
        .nothing_due { color: #555; }
        .foot { margin-top: 18px; font-size: 8.5px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
    </style>
</head>
<body>
    <table class="head"><tr>
        <td>
            @if ($logo)<img src="{{ $logo }}" class="logo" alt="logo"><br>@endif
            <span class="company">{{ $company?->name }}</span><br>
            <span class="muted">
                {{ $company?->location }}@if($company?->tech_number) · Tel: {{ $company->tech_number }}@endif
                @if($company?->momo_number)<br>MOMO: {{ $company->momo_number }}@endif
            </span>
        </td>
        <td>
            <h1>STATEMENT OF ACCOUNT</h1>
            <div class="muted" style="text-align: right;">{{ $month($from) }} – {{ $month($to) }}<br>Printed {{ now()->format('d M Y') }}</div>
        </td>
    </tr></table>

    <table class="box"><tr>
        <td>
            <span class="label">Customer</span><br>
            <strong>{{ $customer->name }}</strong><br>
            {{ $customer->zone?->name }}@if($customer->location) · {{ $customer->location }}@endif<br>
            @if($customer->phone){{ $customer->phone }}@endif
        </td>
        <td>
            <span class="label">Account</span> <strong>{{ $accountCode }}</strong><br>
            <span class="label">Monthly rate</span> {{ $money($customer->bill) }} FCFA<br>
            <span class="label">Status</span> {{ ucfirst((string) $customer->status) }}
        </td>
    </tr></table>

    <table class="summary"><tr>
        <td>Opening balance<span class="v">{{ $balance($opening_balance) }} FCFA</span></td>
        <td>Monthly charges<span class="v">{{ $money($total_billed) }} FCFA</span></td>
        <td>Payments received<span class="v">{{ $money($total_paid) }} FCFA</span></td>
        <td>Closing balance<span class="v">{{ $balance($closing_balance) }} FCFA</span></td>
    </tr></table>

    <h2>Bills</h2>
    @if (count($bills) === 0)
        <p class="muted">No bills in this period.</p>
    @else
        <table class="list">
            <tr>
                <th>Month</th><th>Bill no.</th><th class="r">Monthly charge</th><th class="r">Amount due</th>
                <th class="r">Paid toward it</th><th>Status</th><th class="r">Balance after</th>
            </tr>
            @foreach ($bills as $bill)
                <tr>
                    <td>{{ $month($bill['period']) }}</td>
                    <td>{{ $bill['number'] }}</td>
                    <td class="r">{{ $money($bill['monthly_charge']) }}</td>
                    <td class="r">{{ $money($bill['amount_due']) }}</td>
                    <td class="r">{{ $money($bill['paid']) }}</td>
                    <td class="{{ $bill['status'] }}">{{ $statusLabels[$bill['status']] ?? $bill['status'] }}@if($bill['prepaid_months_remaining'] > 0) · {{ $bill['prepaid_months_remaining'] }} prepaid left @endif</td>
                    <td class="r">{{ $balance($bill['balance']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>Payments received</h2>
    @if (count($payments) === 0)
        <p class="muted">No payments in this period.</p>
    @else
        <table class="list">
            <tr><th>Date</th><th>Receipt</th><th>Type</th><th class="r">Amount (FCFA)</th></tr>
            @foreach ($payments as $payment)
                <tr>
                    <td>{{ $payment['date'] ? \Illuminate\Support\Carbon::parse($payment['date'])->format('d M Y') : '—' }}</td>
                    <td>{{ $payment['receipt_number'] ?? '—' }}</td>
                    <td>{{ $methodLabels[$payment['method']] ?? ucfirst((string) $payment['method']) }}</td>
                    <td class="r">{{ $money($payment['amount']) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <div class="foot">
        Balances: an amount marked CR is credit in the customer's favour. "Paid toward it" counts the payments applied when the following month was billed
        (or, for the latest bill, payments received since). Questions about this statement? Contact {{ $company?->name }}@if($company?->tech_number) on {{ $company->tech_number }}@endif.
        @if($company?->rccm_number) · RCCM: {{ $company->rccm_number }}@endif @if($company?->niu) · NIU: {{ $company->niu }}@endif
    </div>
</body>
</html>
