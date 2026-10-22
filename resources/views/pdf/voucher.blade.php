@php
    /**
     * The printed voucher / bon de caisse — App\Http\Controllers\
     * VoucherController::pdf() (docs/plans/cash-controls.md, wave 5). Shows
     * who asked, who approved and when, who paid from which account, and
     * leaves boxes for the handwritten signatures — above all the payee's
     * "received by", which is what the signed copy upload records.
     *
     * @var \App\Models\Voucher $voucher
     */
    $money = fn ($value): string => number_format((float) $value, 0, '.', ' ').' FCFA';
    $methods = ['cash' => 'Cash', 'mtn_momo' => 'MTN MoMo', 'orange_money' => 'Orange Money', 'bank' => 'Bank'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Voucher {{ $voucher->number }}</title>
    <style>
        @page { margin: 8mm; }
        body { font-family: Helvetica, Arial, sans-serif; font-size: 9pt; color: #111; margin: 0; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .company { font-weight: bold; font-size: 11pt; }
        .meta { font-size: 7.5pt; color: #444; }
        .title { font-size: 13pt; font-weight: bold; letter-spacing: 1px; text-align: right; }
        .number { font-family: 'DejaVu Sans Mono', monospace; font-size: 11pt; color: #b91c1c; font-weight: bold; text-align: right; }
        .box { border: 1px solid #333; margin-top: 3mm; }
        .box td { padding: 1.5mm 2.5mm; border-bottom: 1px solid #ccc; vertical-align: top; }
        .box td.k { width: 30%; color: #444; }
        .amount { font-size: 13pt; font-weight: bold; }
        .words { font-style: italic; font-size: 8pt; }
        .sign td { width: 25%; border: 1px solid #333; height: 20mm; vertical-align: top; padding: 1.5mm; font-size: 7.5pt; }
        .stamp { color: #b91c1c; font-weight: bold; border: 1.5px solid #b91c1c; padding: 1mm 2mm; display: inline-block; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td>
                @if ($logo)<img src="{{ $logo }}" style="max-height: 12mm; max-width: 40mm;"><br>@endif
                <span class="company">{{ $company?->name }}</span><br>
                <span class="meta">{{ $company?->location }}@if($company?->phone) · Tel {{ $company->phone }}@endif</span>
            </td>
            <td>
                <div class="title">{{ $voucher->kind === 'advance' ? 'CASH ADVANCE' : 'PAYMENT VOUCHER' }} / BON DE CAISSE</div>
                <div class="number">{{ $voucher->number }}</div>
                <div class="meta" style="text-align: right;">
                    Status: <span class="stamp">{{ strtoupper($voucher->status) }}</span>
                </div>
            </td>
        </tr>
    </table>

    <table class="box">
        <tr><td class="k">Pay to / Payer à</td><td>{{ $voucher->payee_name }}</td></tr>
        <tr><td class="k">For / Objet</td><td>{{ $voucher->purpose }}@if($voucher->category) ({{ $voucher->category->name }})@endif</td></tr>
        <tr>
            <td class="k">Amount / Montant</td>
            <td><span class="amount">{{ $money($voucher->amount) }}</span><br><span class="words">{{ $words['en'] }} — {{ $words['fr'] }}</span></td>
        </tr>
        <tr><td class="k">Requested by</td><td>{{ $voucher->requestedBy?->name }} · {{ $voucher->created_at?->format('d M Y H:i') }} · needed on {{ $voucher->needed_on?->format('d M Y') }}</td></tr>
        <tr>
            <td class="k">Approved by</td>
            <td>
                @forelse ($voucher->approvals as $approval)
                    {{ $approval->user?->name }} — {{ $approval->decision }} {{ $approval->decided_at?->format('d M Y H:i') }}@if($approval->note) ({{ $approval->note }})@endif<br>
                @empty
                    —
                @endforelse
                @if ($voucher->approval_tier === 'stakeholders')<span class="meta">Above the company limit — approved by the stakeholders.</span>@endif
            </td>
        </tr>
        @if ($voucher->paid_at)
            <tr>
                <td class="k">Paid by</td>
                <td>
                    {{ $voucher->paidBy?->name }} · {{ $voucher->paid_at->format('d M Y H:i') }} · from {{ $voucher->paidFrom?->name }}
                    · {{ $methods[$voucher->payment_method] ?? $voucher->payment_method }}@if($voucher->payment_reference) ({{ $voucher->payment_reference }})@endif
                </td>
            </tr>
            <tr><td class="k">Received by</td><td>{{ $voucher->received_by_name }}</td></tr>
        @endif
        @if ($voucher->kind === 'advance' && $voucher->settle_due_on)
            <tr><td class="k">Justify by</td><td>{{ $voucher->settle_due_on->format('d M Y') }} — bring receipts for what was spent and return any change.</td></tr>
        @endif
    </table>

    <table class="sign" style="margin-top: 4mm;">
        <tr>
            <td>Requested by<br><br>{{ $voucher->requestedBy?->name }}</td>
            <td>Approved by</td>
            <td>Paid by (cashier)<br><br>{{ $voucher->paidBy?->name }}</td>
            <td>Received by (payee) — signature</td>
        </tr>
    </table>
    <div class="meta" style="margin-top: 2mm;">Printed {{ now()->format('d M Y H:i') }}. Keep the signed copy — upload a photo of it to the voucher in CNCMS.</div>
</body>
</html>
