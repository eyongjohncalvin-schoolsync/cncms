<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manuscript {{ $period }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 9px;
            color: #111;
            margin: 0;
            padding: 16px;
        }
        .title {
            font-size: 15px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .subtitle {
            font-size: 11px;
            margin-bottom: 10px;
        }
        table.summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        table.summary td {
            padding: 4px 8px;
            border: 1px solid #999;
            text-align: center;
        }
        table.summary td.label {
            font-weight: bold;
            background: #eee;
        }
        table.register {
            width: 100%;
            border-collapse: collapse;
        }
        table.register th,
        table.register td {
            border: 1px solid #999;
            padding: 3px 4px;
            text-align: left;
        }
        table.register th {
            background: #ddd;
            font-weight: bold;
        }
        table.register td.num,
        table.register th.num {
            text-align: right;
        }
        table.register td.paid {
            width: 40px;
        }
        .letterhead {
            width: 100%;
            margin-bottom: 8px;
        }
        .letterhead .logo {
            max-height: 44px;
            max-width: 140px;
        }
        .registration {
            font-size: 8px;
            color: #444;
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

    <div class="title">{{ $company?->name }} -- Manuscript Register</div>
    <div class="subtitle">
        Period: {{ $period }}
        @if ($company?->head_office)
            &nbsp;|&nbsp; {{ $company->head_office }}
        @endif
        @if ($company?->rccm_number || $company?->niu)
            <div class="registration">
                @if ($company->rccm_number) RCCM: {{ $company->rccm_number }} @endif
                @if ($company->rccm_number && $company->niu) &nbsp;|&nbsp; @endif
                @if ($company->niu) NIU: {{ $company->niu }} @endif
            </div>
        @endif
    </div>

    <table class="summary">
        <tr>
            <td class="label">Total Customers</td>
            <td class="label">Total Billed</td>
            <td class="label">Total Arrears</td>
            <td class="label">Total Credit</td>
            <td class="label">Total Collected</td>
            <td class="label">Collection Rate</td>
        </tr>
        <tr>
            <td>{{ $summary['total_customers'] }}</td>
            <td>{{ number_format((float) $summary['total_bill'], 2) }}</td>
            <td>{{ number_format((float) $summary['total_arrears'], 2) }}</td>
            <td>{{ number_format((float) $summary['total_credit'], 2) }}</td>
            <td>{{ number_format((float) $summary['total_collected'], 2) }}</td>
            <td>{{ $summary['collection_rate'] }}%</td>
        </tr>
    </table>

    <table class="register">
        <thead>
            <tr>
                <th>No</th>
                <th>Name</th>
                <th>Code</th>
                <th>Phone</th>
                <th>Zone</th>
                <th>Level</th>
                <th class="num">Bill</th>
                <th class="num">Arrears</th>
                <th class="num">Credit</th>
                <th>Expiry</th>
                <th class="num">Total Bill</th>
                <th>Status</th>
                <th class="paid">Paid</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($manuscripts as $index => $manuscript)
                @php
                    $customer = $manuscript->customer;
                    $status = $customer?->status === 'disconnected' ? 'discon...' : $customer?->status;
                    $expiry = $manuscript->payment_expiration
                        ? $manuscript->payment_expiration->format('M y')
                        : '-';
                @endphp
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $customer?->name }}</td>
                    <td>{{ substr($customer?->uuid ?? '', 0, 8) }}</td>
                    <td>{{ $customer?->phone }}</td>
                    <td>{{ $customer?->zone?->name }}</td>
                    <td>{{ $customer?->level }}</td>
                    <td class="num">{{ number_format((float) $manuscript->bill, 2) }}</td>
                    <td class="num">{{ number_format((float) $manuscript->total_arrears, 2) }}</td>
                    <td class="num">{{ number_format((float) $manuscript->credit, 2) }}</td>
                    <td>{{ $expiry }}</td>
                    <td class="num">{{ number_format((float) $manuscript->total_bill, 2) }}</td>
                    <td>{{ $status }}</td>
                    <td class="paid"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
