<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manuscript {{ $period }}</title>
    <style>
        @page { margin: 14px 16px; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 8.5px;
            color: #111;
            margin: 0;
        }
        .title { font-size: 14px; font-weight: bold; }
        .subtitle { font-size: 10px; margin-bottom: 8px; }
        .registration { font-size: 8px; color: #444; }
        .logo { max-height: 38px; max-width: 120px; margin-bottom: 4px; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        table.register { table-layout: fixed; }
        table.summary { margin-bottom: 10px; }
        table.summary td {
            padding: 3px 6px;
            border-bottom: 1px solid #bbb;
            text-align: center;
        }
        table.summary td.label { font-weight: bold; background: #eee; }
        table.register th {
            background: #ddd;
            font-weight: bold;
            padding: 3px 4px;
            text-align: left;
            border-bottom: 1px solid #999;
        }
        table.register td {
            padding: 2px 4px;
            border-bottom: 1px solid #ddd;
        }
        table.register th.num, table.register td.num { text-align: right; }
        /* Narrowed text columns: clip rather than let the fixed table overflow A4. */
        table.register td.clip {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        /* Blank column the manager fills in by hand after collecting. */
        table.register th.paid, table.register td.paid { border-left: 1px solid #999; }
        col.c-no { width: 3%; }
        col.c-name { width: 19%; }
        col.c-code { width: 7%; }
        col.c-zone { width: 9%; }
        col.c-bill { width: 9%; }
        col.c-arrears { width: 10%; }
        col.c-credit { width: 9%; }
        col.c-total { width: 10%; }
        col.c-paid { width: 12%; }
        col.c-status { width: 5%; }
        col.c-expiry { width: 7%; }
    </style>
</head>
<body>
    @if ($company?->logoDataUri())
        <img src="{{ $company->logoDataUri() }}" class="logo" alt="{{ $company->name }} logo">
    @endif

    <div class="title">{{ $company?->name }} &mdash; Manuscript Register</div>
    <div class="subtitle">
        Period: {{ $period }}
        @if ($company?->head_office) &nbsp;|&nbsp; {{ $company->head_office }} @endif
        @if ($company?->rccm_number || $company?->niu)
            <div class="registration">
                @if ($company?->rccm_number) RCCM: {{ $company->rccm_number }} @endif
                @if ($company?->rccm_number && $company?->niu) &nbsp;|&nbsp; @endif
                @if ($company?->niu) NIU: {{ $company->niu }} @endif
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

    @php
        // Display-only status abbreviations to save column width — stored data is untouched.
        $statusAbbr = ['disconnected' => 'disc', 'suspended' => 'susp'];
    @endphp

    <table class="register">
        <colgroup>
            <col class="c-no"><col class="c-name"><col class="c-code"><col class="c-zone">
            <col class="c-bill"><col class="c-arrears"><col class="c-credit"><col class="c-total">
            <col class="c-paid"><col class="c-status"><col class="c-expiry">
        </colgroup>
        <thead>
            <tr>
                <th>No</th>
                <th>Name</th>
                <th>Code</th>
                <th>Zone</th>
                <th class="num">Bill</th>
                <th class="num">Arrears</th>
                <th class="num">Credit</th>
                <th class="num">Total Bill</th>
                <th class="num paid">Paid</th>
                <th>Status</th>
                <th>Expiry</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($manuscripts as $index => $manuscript)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="clip">{{ $manuscript->customer?->name }}</td>
                    <td>{{ substr($manuscript->customer?->uuid ?? '', 0, 8) }}</td>
                    <td class="clip">{{ $manuscript->customer?->zone?->name }}</td>
                    <td class="num">{{ number_format((float) $manuscript->bill, 2) }}</td>
                    <td class="num">{{ number_format((float) $manuscript->total_arrears, 2) }}</td>
                    <td class="num">{{ number_format((float) $manuscript->credit, 2) }}</td>
                    <td class="num">{{ number_format((float) $manuscript->total_bill, 2) }}</td>
                    <td class="paid"></td>
                    <td>{{ $statusAbbr[$manuscript->customer?->status] ?? $manuscript->customer?->status }}</td>
                    <td>{{ $manuscript->expiryLabel() }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
