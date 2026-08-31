{{--
    "Kumba Compact" — receipt-style, sized to fit a ~1/2 or 1/4 A4 cell
    (this is the template forced by the bulk N-up grid whenever
    bills_per_page > 1 — see pdf/bills/_grid.blade.php). Single column,
    condensed, TOTAL AMOUNT DUE reversed out white-on-black (survives weak
    toner and photocopying, grayscale-native), no amount-in-words, no
    signature stub, minimal logo. Self-contained fragment, scoped under
    .bill-compact — see classic.blade.php's doc comment for why.

    Expects the same variables as classic.blade.php.
--}}
<style>
    .bill-compact {
        font-family: 'DejaVu Sans', sans-serif;
        font-size: 8.5px;
        color: #111;
        border: 1px solid #333;
        padding: 6px;
    }
    .bill-compact .kc-sample-flag {
        text-align: center;
        font-weight: bold;
        color: #b91c1c;
        border: 1px dashed #b91c1c;
        padding: 2px;
        margin-bottom: 4px;
        font-size: 7.5px;
    }
    .bill-compact table.kc-head {
        width: 100%;
        border-bottom: 1px solid #333;
        padding-bottom: 3px;
        margin-bottom: 4px;
    }
    .bill-compact .kc-logo {
        max-height: 26px;
        max-width: 36px;
    }
    .bill-compact .kc-name {
        font-size: 11px;
        font-weight: bold;
    }
    .bill-compact .kc-sub {
        font-size: 7.5px;
        color: #333;
    }
    .bill-compact .kc-title {
        text-align: right;
        font-size: 9px;
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
        font-size: 12px;
        font-family: 'DejaVu Sans Mono', monospace;
    }
    .bill-compact .kc-warning {
        font-size: 7px;
        margin-bottom: 3px;
    }
    .bill-compact .kc-footer {
        font-size: 6.5px;
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
            @if ($logo_data_uri)
                <td style="width: 40px; vertical-align: top;"><img src="{{ $logo_data_uri }}" class="kc-logo" alt="logo"></td>
            @endif
            <td style="vertical-align: top;">
                <div class="kc-name">{{ $company?->name }}</div>
                <div class="kc-sub">{{ $company?->location }}</div>
            </td>
            <td class="kc-title" style="vertical-align: top;">
                BILL<br>{{ $period_label }}
            </td>
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
