{{--
    "Signal Modern" — full A4, branded accent band across the top with a
    large logo, tabular figures, TOTAL AMOUNT DUE as a large hero number in
    a tinted panel, payment methods shown as labeled chips. Self-contained
    fragment, scoped under .bill-modern — see classic.blade.php's doc
    comment for why.

    Expects the same variables as classic.blade.php.
--}}
<style>
    .bill-modern {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 11px;
        color: #111;
    }
    .bill-modern .mb-sample-flag {
        text-align: center;
        font-weight: bold;
        color: #b91c1c;
        border: 1px dashed #b91c1c;
        padding: 3px;
        margin-bottom: 6px;
        font-size: 10px;
    }
    .bill-modern table.mb-band {
        width: 100%;
        background: #0f172a;
        color: #fff;
        margin-bottom: 10px;
    }
    .bill-modern table.mb-band td {
        padding: 10px 12px;
        vertical-align: middle;
    }
    .bill-modern .mb-logo {
        max-height: 46px;
        max-width: 90px;
        background: #fff;
        padding: 3px;
    }
    .bill-modern .mb-company-name {
        font-size: 17px;
        font-weight: bold;
    }
    .bill-modern .mb-company-sub {
        font-size: 9px;
        color: #cbd5e1;
    }
    .bill-modern td.mb-doc-id {
        text-align: right;
        font-size: 9px;
        color: #e2e8f0;
    }
    .bill-modern .mb-doc-title {
        font-size: 13px;
        font-weight: bold;
        color: #fff;
    }
    .bill-modern table.mb-info {
        width: 100%;
        margin-bottom: 10px;
    }
    .bill-modern table.mb-info td {
        vertical-align: top;
        width: 50%;
        padding: 0 4px;
    }
    .bill-modern table.mb-fields td {
        padding: 2px 0;
        font-size: 10px;
    }
    .bill-modern table.mb-fields td.mb-label {
        width: 40%;
        color: #555;
    }
    .bill-modern .mb-hero {
        background: #eef2ff;
        border: 1px solid #c7d2fe;
        padding: 10px 12px;
        margin-bottom: 10px;
    }
    .bill-modern .mb-hero-label {
        font-size: 9px;
        text-transform: uppercase;
        color: #4338ca;
        font-weight: bold;
    }
    .bill-modern .mb-hero-value {
        font-size: 24px;
        font-weight: bold;
        color: #1e1b4b;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-modern table.mb-breakdown {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 10px;
    }
    .bill-modern table.mb-breakdown th {
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        color: #555;
        border-bottom: 1px solid #ccc;
        padding: 3px 4px;
    }
    .bill-modern table.mb-breakdown th.mb-num,
    .bill-modern table.mb-breakdown td.mb-num {
        text-align: right;
    }
    .bill-modern table.mb-breakdown td {
        padding: 4px;
        border-bottom: 1px solid #eee;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-modern table.mb-breakdown td.mb-desc {
        font-family: 'DejaVu Sans', sans-serif;
    }
    .bill-modern table.mb-chips {
        width: 100%;
        margin-bottom: 8px;
    }
    .bill-modern table.mb-chips td {
        vertical-align: top;
        padding: 0 4px 0 0;
    }
    .bill-modern .mb-chip {
        display: block;
        border: 1px solid #ddd;
        border-radius: 4px;
        padding: 6px 8px;
        font-size: 9px;
    }
    .bill-modern .mb-chip-label {
        text-transform: uppercase;
        font-size: 7.5px;
        color: #666;
        font-weight: bold;
    }
    .bill-modern .mb-fine-note {
        font-size: 8.5px;
        color: #7c2d12;
        background: #fff7ed;
        border: 1px solid #fed7aa;
        padding: 5px 8px;
        margin-bottom: 8px;
    }
    .bill-modern .mb-footer {
        font-size: 8px;
        color: #444;
        border-top: 1px solid #ccc;
        padding-top: 4px;
    }
</style>
<div class="bill-modern">
    @if (! empty($is_sample))
        <div class="mb-sample-flag">SAMPLE PREVIEW — placeholder data, no customers exist yet</div>
    @endif

    <table class="mb-band">
        <tr>
            @if ($logo_data_uri)
                <td style="width: 100px;"><img src="{{ $logo_data_uri }}" class="mb-logo" alt="{{ $company?->name }} logo"></td>
            @endif
            <td>
                <div class="mb-company-name">{{ $company?->name }}</div>
                <div class="mb-company-sub">
                    {{ $company?->location }}
                    @if ($company?->head_office) &mdash; {{ $company->head_office }} @endif
                </div>
            </td>
            <td class="mb-doc-id">
                <div class="mb-doc-title">BILL &mdash; {{ $period_label }}</div>
                <div>{{ $bill_number }}</div>
                <div>Issued {{ now()->format('d M Y') }}</div>
            </td>
        </tr>
    </table>

    <table class="mb-info">
        <tr>
            <td>
                <table class="mb-fields">
                    <tr><td class="mb-label">Customer</td><td>{{ $customer->name }}</td></tr>
                    <tr><td class="mb-label">Account Code</td><td>{{ $account_code }}</td></tr>
                    <tr><td class="mb-label">Zone</td><td>{{ $customer->zone?->name }}</td></tr>
                    <tr><td class="mb-label">Location</td><td>{{ $customer->location }}</td></tr>
                    @if ($customer->phone)
                        <tr><td class="mb-label">Tel</td><td>{{ $customer->phone }}</td></tr>
                    @endif
                </table>
            </td>
            <td>
                <table class="mb-fields">
                    <tr><td class="mb-label">Payment Deadline</td><td>{{ $deadline }}</td></tr>
                    <tr><td class="mb-label">Issuer Email</td><td>{{ $company?->email }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="mb-hero">
        <div class="mb-hero-label">Total Amount Due</div>
        <div class="mb-hero-value">{{ number_format((float) $manuscript->total_bill, 2) }} FCFA</div>
    </div>

    <table class="mb-breakdown">
        <thead>
            <tr>
                <th class="mb-desc">Item</th>
                <th class="mb-num">Amount (FCFA)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td class="mb-desc">Net Monthly Bill</td>
                <td class="mb-num">{{ number_format((float) $manuscript->bill, 2) }}</td>
            </tr>
            <tr>
                <td class="mb-desc">Previous Balance / Arrears</td>
                <td class="mb-num">{{ number_format((float) $manuscript->total_arrears, 2) }}</td>
            </tr>
            <tr>
                <td class="mb-desc">Credit Balance</td>
                <td class="mb-num">-{{ number_format((float) $manuscript->credit, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="mb-fine-note">
        Late payment triggers disconnection. Reconnection requires the outstanding balance plus a
        {{ number_format((float) ($company?->reconnection_fine ?? 0), 2) }} FCFA reconnection fine.
    </div>

    <table class="mb-chips">
        <tr>
            @if ($company?->momo_number)
                <td style="width: 55%;">
                    <span class="mb-chip">
                        <span class="mb-chip-label">MOMO Payment</span><br>
                        {{ $company->momo_number }} &mdash; {{ $company->momo_name }}
                    </span>
                </td>
            @endif
            @if ($company?->tech_number)
                <td>
                    <span class="mb-chip">
                        <span class="mb-chip-label">Technical Support</span><br>
                        {{ $company->tech_number }}
                    </span>
                </td>
            @endif
        </tr>
    </table>

    @if ($company?->rccm_number || $company?->niu)
        <div class="mb-footer">
            @if ($company->rccm_number) RCCM: {{ $company->rccm_number }} @endif
            @if ($company->rccm_number && $company->niu) &nbsp;|&nbsp; @endif
            @if ($company->niu) NIU: {{ $company->niu }} @endif
        </div>
    @endif
</div>
