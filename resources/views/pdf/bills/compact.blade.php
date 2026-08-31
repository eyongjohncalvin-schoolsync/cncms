{{--
    "Kumba Compact" — receipt-style, the template forced by the bulk N-up
    grid whenever bills_per_page > 1 (see pdf/bills/_grid.blade.php). In the
    grid it renders inside one of 2/3/4 side-by-side full-height strips, so
    it scales its type down as the strips get narrower ($grid_columns).
    Single column, condensed, TOTAL AMOUNT DUE reversed out white-on-black
    (survives weak toner / photocopying), no amount-in-words, no signature
    stub, minimal logo. Self-contained fragment scoped under .bill-compact.

    Expects the same variables as classic.blade.php, plus an optional
    $grid_columns (1 when printed alone, 2/3/4 from the N-up grid).
--}}
@php
    $gc = (int) ($grid_columns ?? 1);
    // Base type scale shrinks as the strip narrows so a 1/3 or 1/4-width
    // column doesn't wrap every field onto three lines.
    $fs = match (true) { $gc >= 4 => 6.5, $gc >= 3 => 7.5, $gc >= 2 => 8.0, default => 8.5 };
@endphp
<style>
    .bill-compact {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: {{ $fs }}px;
        color: #111;
        border: 1px solid #333;
        padding: {{ $gc >= 3 ? 4 : 6 }}px;
    }
    .bill-compact .kc-sample-flag {
        text-align: center;
        font-weight: bold;
        color: #b91c1c;
        border: 1px dashed #b91c1c;
        padding: 2px;
        margin-bottom: 4px;
        font-size: {{ $fs - 1 }}px;
    }
    .bill-compact table.kc-head {
        width: 100%;
        border-bottom: 1px solid #333;
        padding-bottom: 3px;
        margin-bottom: 4px;
    }
    .bill-compact .kc-logo {
        max-height: {{ $gc >= 3 ? 20 : 26 }}px;
        max-width: {{ $gc >= 3 ? 28 : 36 }}px;
    }
    .bill-compact .kc-name {
        font-size: {{ $fs + 2 }}px;
        font-weight: bold;
    }
    .bill-compact .kc-sub {
        font-size: {{ $fs - 1 }}px;
        color: #333;
    }
    .bill-compact .kc-title {
        text-align: right;
        font-size: {{ $fs }}px;
        font-weight: bold;
    }
    .bill-compact table.kc-fields {
        width: 100%;
        margin-bottom: 4px;
    }
    .bill-compact table.kc-fields td {
        padding: 1px 0;
    }
    .bill-compact table.kc-fields td.kc-label {
        width: 40%;
        font-weight: bold;
    }
    .bill-compact table.kc-money {
        width: 100%;
        border-collapse: collapse;
        margin-bottom: 4px;
    }
    .bill-compact table.kc-money td {
        padding: 1.5px 0;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-compact table.kc-money td.kc-mlabel {
        font-family: 'DejaVu Sans', sans-serif;
    }
    .bill-compact table.kc-money td.kc-mval {
        text-align: right;
    }
    .bill-compact .kc-due {
        background: #111;
        color: #fff;
        padding: 4px 6px;
        margin-bottom: 4px;
        font-weight: bold;
    }
    .bill-compact table.kc-due-inner {
        width: 100%;
    }
    .bill-compact table.kc-due-inner td.kc-due-val {
        text-align: right;
        font-size: {{ $fs + 3 }}px;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-compact .kc-warning {
        font-size: {{ $fs - 1.5 }}px;
        margin-bottom: 3px;
    }
    .bill-compact .kc-footer {
        font-size: {{ $fs - 2 }}px;
        color: #444;
        border-top: 1px solid #ccc;
        padding-top: 2px;
    }
</style>
<div class="bill-compact">
    @if (! empty($is_sample))
        <div class="kc-sample-flag">SAMPLE</div>
    @endif

    <table class="kc-head">
        <tr>
            @if ($logo_data_uri && $gc < 4)
                <td style="width: {{ $gc >= 3 ? 30 : 40 }}px; vertical-align: top;"><img src="{{ $logo_data_uri }}" class="kc-logo" alt="logo"></td>
            @endif
            <td style="vertical-align: top;">
                <div class="kc-name">{{ $company?->name }}</div>
                <div class="kc-sub">{{ $company?->location }}</div>
                {{-- Narrow strips can't spare a third column for the title —
                     fold it under the company name. --}}
                @if ($gc >= 3)
                    <div class="kc-sub" style="font-weight: bold; color: #111;">BILL &mdash; {{ $period_label }}</div>
                @endif
            </td>
            @if ($gc < 3)
                <td class="kc-title" style="vertical-align: top;">
                    BILL<br>{{ $period_label }}
                </td>
            @endif
        </tr>
    </table>

    <table class="kc-fields">
        <tr>
            <td class="kc-label">Bill No</td>
            <td>{{ $bill_number }}</td>
        </tr>
        <tr>
            <td class="kc-label">Customer</td>
            <td>{{ $customer->name }}</td>
        </tr>
        <tr>
            <td class="kc-label">Account</td>
            <td>{{ $account_code }}</td>
        </tr>
        <tr>
            <td class="kc-label">Zone</td>
            <td>{{ $customer->zone?->name }}</td>
        </tr>
        @if ($customer->phone)
            <tr>
                <td class="kc-label">Tel</td>
                <td>{{ $customer->phone }}</td>
            </tr>
        @endif
        <tr>
            <td class="kc-label">Deadline</td>
            <td>{{ $deadline }}</td>
        </tr>
    </table>

    <table class="kc-money">
        <tr>
            <td class="kc-mlabel">Bill</td>
            <td class="kc-mval">{{ number_format((float) $manuscript->bill, 2) }}</td>
        </tr>
        <tr>
            <td class="kc-mlabel">Arrears</td>
            <td class="kc-mval">{{ number_format((float) $manuscript->total_arrears, 2) }}</td>
        </tr>
        <tr>
            <td class="kc-mlabel">Credit</td>
            <td class="kc-mval">-{{ number_format((float) $manuscript->credit, 2) }}</td>
        </tr>
    </table>

    <div class="kc-due">
        <table class="kc-due-inner">
            <tr>
                <td>TOTAL DUE</td>
                <td class="kc-due-val">{{ number_format((float) $manuscript->total_bill, 2) }} FCFA</td>
            </tr>
        </table>
    </div>

    <div class="kc-warning">
        Reconnection fine: {{ number_format((float) ($company?->reconnection_fine ?? 0), 2) }} FCFA.
        MOMO: {{ $company?->momo_number }} ({{ $company?->momo_name }}).
        Support: {{ $company?->tech_number }}.
    </div>

    @if ($company?->rccm_number || $company?->niu)
        <div class="kc-footer">
            @if ($company->rccm_number) RCCM: {{ $company->rccm_number }} @endif
            @if ($company->rccm_number && $company->niu) | @endif
            @if ($company->niu) NIU: {{ $company->niu }} @endif
        </div>
    @endif
</div>
