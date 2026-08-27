<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Bill {{ $period_label }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 12px;
            color: #111;
            margin: 0;
            padding: 24px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 12px;
        }
        table.fields {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 16px;
        }
        table.fields td {
            padding: 3px 0;
            vertical-align: top;
        }
        table.fields td.label {
            width: 160px;
            font-weight: bold;
        }
        .warning {
            margin: 10px 0;
            font-style: italic;
        }
        .account-heading {
            font-size: 13px;
            font-weight: bold;
            margin-top: 16px;
            margin-bottom: 6px;
            border-top: 1px solid #333;
            padding-top: 10px;
        }
        table.account {
            width: 100%;
            border-collapse: collapse;
        }
        table.account td {
            padding: 3px 0;
        }
        table.account td.label {
            width: 200px;
        }
        table.account td.value {
            text-align: right;
            font-weight: bold;
        }
        table.account tr.total td {
            border-top: 1px solid #333;
            padding-top: 6px;
            font-size: 13px;
        }
        .letterhead {
            width: 100%;
            margin-bottom: 12px;
        }
        .letterhead .logo {
            max-height: 60px;
            max-width: 160px;
        }
        .registration {
            font-size: 9px;
            color: #444;
            margin-top: 12px;
            border-top: 1px solid #ccc;
            padding-top: 6px;
        }
    </style>
</head>
<body>
    @if ($company?->logoDataUri())
        <table class="letterhead">
            <tr>
                <td><img src="{{ $company->logoDataUri() }}" class="logo" alt="{{ $company->name }} logo"></td>
            </tr>
        </table>
    @endif

    <div class="title">BILL: {{ $period_label }}</div>

    <table class="fields">
        <tr>
            <td class="label">From:</td>
            <td>{{ $company?->name }} -- {{ $company?->location }} -- Phone: {{ $company?->tech_number }}</td>
        </tr>
        @if ($company?->head_office)
        <tr>
            <td class="label">Head Office:</td>
            <td>{{ $company->head_office }}</td>
        </tr>
        @endif
        <tr>
            <td class="label">To:</td>
            <td>{{ $customer->name }}</td>
        </tr>
        <tr>
            <td class="label">Zone:</td>
            <td>{{ $customer->zone?->name }}</td>
        </tr>
        <tr>
            <td class="label">Tel:</td>
            <td>{{ $customer->phone }}</td>
        </tr>
        <tr>
            <td class="label">Payment Deadline:</td>
            <td>{{ $deadline }}</td>
        </tr>
        <tr>
            <td class="label">Code:</td>
            <td>{{ substr($customer->uuid, 0, 8) }}</td>
        </tr>
        <tr>
            <td class="label">Location:</td>
            <td>{{ $customer->location }}</td>
        </tr>
    </table>

    <div class="warning">
        {{-- Was hardcoded to "2000 FCFA" even though the reconnection fine
             became admin-configurable (Company::cached()->reconnection_fine)
             earlier this cycle — fixed to read the real configured value.
             This view is superseded by resources/views/pdf/bills/*.blade.php
             (see CustomerController::printBill()/Api\BillController::print()),
             kept here only in case anything still references it directly. --}}
        Warning: Pay before the due date's done, skip the {{ number_format((float) ($company?->reconnection_fine ?? 0), 2) }} FCFA reconnect fine!
    </div>

    <table class="fields">
        <tr>
            <td class="label">Technical/Billing:</td>
            <td>{{ $company?->tech_number }}</td>
        </tr>
        <tr>
            <td class="label">MOMO NUMBERS:</td>
            <td>{{ $company?->momo_number }}, {{ $company?->momo_name }}</td>
        </tr>
    </table>

    <div class="account-heading">Current Account Details:</div>

    <table class="account">
        <tr>
            <td class="label">Net Monthly Bill:</td>
            <td class="value">{{ number_format((float) $manuscript->bill, 2) }} FCFA</td>
        </tr>
        <tr>
            <td class="label">Credit:</td>
            <td class="value">{{ number_format((float) $manuscript->credit, 2) }}</td>
        </tr>
        <tr>
            <td class="label">Arrears:</td>
            <td class="value">{{ number_format((float) $manuscript->total_arrears, 2) }} FCFA</td>
        </tr>
        <tr class="total">
            <td class="label">Total:</td>
            <td class="value">{{ number_format((float) $manuscript->total_bill, 2) }} FCFA</td>
        </tr>
    </table>

    @if ($company?->rccm_number || $company?->niu)
        <div class="registration">
            @if ($company->rccm_number) RCCM: {{ $company->rccm_number }} @endif
            @if ($company->rccm_number && $company->niu) &nbsp;|&nbsp; @endif
            @if ($company->niu) NIU: {{ $company->niu }} @endif
        </div>
    @endif
</body>
</html>
