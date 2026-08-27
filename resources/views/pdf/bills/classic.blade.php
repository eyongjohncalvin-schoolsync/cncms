{{--
    "Classic Ledger" — formal, ruled two-column letterhead layout (closest
    to the old resources/views/pdf/bill.blade.php's structure), boxed
    sections, an amount-in-words line, and a signature/tear-off stub for the
    delivering collector to sign. A self-contained fragment (no
    <html>/<head>/<body>) so it can be @include()'d either standalone (see
    pdf/bills/show.blade.php, single-bill printing) or once per cell inside
    the bulk N-up grid (pdf/bills/_grid.blade.php) — every rule below is
    scoped under .bill-classic so several copies can be @include()'d onto
    the same page (density > 1) without leaking styles into a different
    template's cells.

    Expects: $company, $customer, $manuscript, $period_label, $deadline,
    $account_code, $bill_number, $logo_data_uri, and optionally $is_sample
    (bool, only set by ManuscriptService::sampleBillData()).
--}}
<style>
    .bill-classic {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 11px;
        color: #111;
        border: 1.5px solid #222;
        padding: 10px;
    }
    .bill-classic .bc-sample-flag {
        text-align: center;
        font-weight: bold;
        color: #b91c1c;
        border: 1px dashed #b91c1c;
        padding: 3px;
        margin-bottom: 6px;
        font-size: 10px;
    }
    .bill-classic table.bc-letterhead {
        width: 100%;
        border-bottom: 2px solid #222;
        padding-bottom: 6px;
        margin-bottom: 8px;
    }
    .bill-classic table.bc-letterhead td.bc-logo-cell {
        width: 70px;
        vertical-align: top;
    }
    .bill-classic .bc-logo {
        max-height: 50px;
        max-width: 64px;
    }
    .bill-classic .bc-issuer-name {
        font-size: 15px;
        font-weight: bold;
    }
    .bill-classic .bc-issuer-detail {
        font-size: 9px;
        color: #333;
    }
    .bill-classic td.bc-doc-id {
        text-align: right;
        vertical-align: top;
        font-size: 9px;
    }
    .bill-classic .bc-doc-title {
        font-size: 13px;
        font-weight: bold;
        margin-bottom: 2px;
    }
    .bill-classic table.bc-box {
        width: 100%;
        border: 1px solid #666;
        border-collapse: collapse;
        margin-bottom: 8px;
    }
    .bill-classic table.bc-box td {
        border: 1px solid #999;
        padding: 3px 6px;
        vertical-align: top;
    }
    .bill-classic table.bc-box td.bc-label {
        width: 34%;
        font-weight: bold;
        background: #f2f2f2;
    }
    .bill-classic table.bc-money {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }
    .bill-classic table.bc-money td {
        padding: 4px 6px;
        border-bottom: 1px solid #ccc;
    }
    .bill-classic table.bc-money td.bc-mval {
        text-align: right;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-classic table.bc-money tr.bc-total td {
        border-top: 2px solid #222;
        border-bottom: 2px solid #222;
        font-size: 15px;
        font-weight: bold;
        padding-top: 6px;
        padding-bottom: 6px;
    }
    .bill-classic .bc-words {
        font-style: italic;
        font-size: 9.5px;
        margin-bottom: 8px;
        border-top: 1px dashed #999;
        padding-top: 4px;
    }
    .bill-classic .bc-warning {
        font-size: 9px;
        margin-bottom: 8px;
        padding: 4px 6px;
        background: #f6f6f6;
        border: 1px solid #ccc;
    }
    .bill-classic .bc-stub {
        margin-top: 10px;
        border-top: 2px dashed #444;
        padding-top: 6px;
    }
    .bill-classic table.bc-stub-grid {
        width: 100%;
    }
    .bill-classic table.bc-stub-grid td {
        font-size: 8.5px;
        vertical-align: bottom;
        padding-top: 14px;
    }
    .bill-classic table.bc-stub-grid td.bc-sig-line {
        border-top: 1px solid #333;
        width: 45%;
    }
    .bill-classic .bc-footer {
        font-size: 8px;
        color: #444;
        margin-top: 6px;
        border-top: 1px solid #ccc;
        padding-top: 4px;
    }
</style>
<div class="bill-classic">
    @if (! empty($is_sample))
        <div class="bc-sample-flag">SAMPLE PREVIEW — placeholder data, no customers exist yet</div>
    @endif

    <table class="bc-letterhead">
        <tr>
            @if ($logo_data_uri)
                <td class="bc-logo-cell"><img src="{{ $logo_data_uri }}" class="bc-logo" alt="{{ $company?->name }} logo"></td>
            @endif
            <td>
                <div class="bc-issuer-name">{{ $company?->name }}</div>
                <div class="bc-issuer-detail">
                    @if ($company?->head_office){{ $company->head_office }}@endif
                    @if ($company?->head_office && $company?->location) &mdash; @endif
                    {{ $company?->location }}
                </div>
                @if ($company?->email)
                    <div class="bc-issuer-detail">{{ $company->email }}</div>
                @endif
            </td>
            <td class="bc-doc-id">
                <div class="bc-doc-title">BILL: {{ $period_label }}</div>
                <div>Bill No: {{ $bill_number }}</div>
                <div>Issued: {{ now()->format('d M Y') }}</div>
                <div>Deadline: {{ $deadline }}</div>
            </td>
        </tr>
    </table>

    <table class="bc-box">
        <tr>
            <td class="bc-label">Customer</td>
            <td>{{ $customer->name }}</td>
        </tr>
        <tr>
            <td class="bc-label">Account Code</td>
            <td>{{ $account_code }}</td>
        </tr>
        <tr>
            <td class="bc-label">Zone</td>
            <td>{{ $customer->zone?->name }}</td>
        </tr>
        <tr>
            <td class="bc-label">Location</td>
            <td>{{ $customer->location }}</td>
        </tr>
        @if ($customer->phone)
            <tr>
                <td class="bc-label">Tel</td>
                <td>{{ $customer->phone }}</td>
            </tr>
        @endif
    </table>

    <table class="bc-money">
        <tr>
            <td>Previous Balance / Arrears</td>
            <td class="bc-mval">{{ number_format((float) $manuscript->total_arrears, 2) }} FCFA</td>
        </tr>
        <tr>
            <td>Net Monthly Bill</td>
            <td class="bc-mval">{{ number_format((float) $manuscript->bill, 2) }} FCFA</td>
        </tr>
        <tr>
            <td>Credit Balance</td>
            <td class="bc-mval">{{ number_format((float) $manuscript->credit, 2) }} FCFA</td>
        </tr>
        <tr class="bc-total">
            <td>TOTAL AMOUNT DUE</td>
            <td class="bc-mval">{{ number_format((float) $manuscript->total_bill, 2) }} FCFA</td>
        </tr>
    </table>

    <div class="bc-words">
        Amount in words: {{ \App\Support\AmountInWords::convert($manuscript->total_bill) }}
    </div>

    <div class="bc-warning">
        Pay before the deadline above to avoid disconnection. Reconnection after disconnection
        requires payment of the outstanding balance plus a
        {{ number_format((float) ($company?->reconnection_fine ?? 0), 2) }} FCFA reconnection fine.
    </div>

    <table class="bc-box">
        @if ($company?->momo_number)
            <tr>
                <td class="bc-label">MOMO Payment</td>
                <td>{{ $company->momo_number }} &mdash; {{ $company->momo_name }}</td>
            </tr>
        @endif
        @if ($company?->tech_number)
            <tr>
                <td class="bc-label">Technical / Billing Support</td>
                <td>{{ $company->tech_number }}</td>
            </tr>
        @endif
    </table>

    <div class="bc-stub">
        <table class="bc-stub-grid">
            <tr>
                <td class="bc-sig-line">Collector's Signature</td>
                <td style="width: 10%;"></td>
                <td class="bc-sig-line">Customer's Signature</td>
            </tr>
        </table>
    </div>

    @if ($company?->rccm_number || $company?->niu)
        <div class="bc-footer">
            @if ($company->rccm_number) RCCM: {{ $company->rccm_number }} @endif
            @if ($company->rccm_number && $company->niu) &nbsp;|&nbsp; @endif
            @if ($company->niu) NIU: {{ $company->niu }} @endif
        </div>
    @endif
</div>
