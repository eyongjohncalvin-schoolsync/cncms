@php
    // Register orientation — defaults to portrait (fits more customer rows per
    // page); landscape is the admin-selectable alternative. Anything other than
    // an explicit "landscape" falls back to portrait.
    $orientation = (($orientation ?? 'portrait') === 'landscape') ? 'landscape' : 'portrait';

    // Fixed-layout column widths (percent, MUST sum to 100), in header order:
    // No, Name, Code, Zone, Bill, Arrears, Credit, Total Bill, Paid, Status, Expiry.
    // Portrait is the tight case and is tuned first; landscape just gets the
    // slack spread back into Name/Zone. Applied directly on the <th> cells:
    // dompdf's table-layout:fixed reads column widths off the first row, and
    // honours per-cell width far more reliably than <colgroup><col> (which it
    // was silently ignoring here — every column came out equal, so "No" had a
    // huge gap and long names spilled over "Code").
    $cols = $orientation === 'landscape'
        ? [3, 22, 8, 12, 9, 9, 9, 9, 10, 5, 4]
        : [3, 18, 8, 10, 9, 9, 9, 10, 11, 7, 6];

    // dompdf does not clip overflowing text (overflow:hidden / text-overflow
    // are no-ops on its table cells), so a long name still draws over the next
    // column even at the right width. Truncate in PHP instead — budget scaled
    // to the Name/Zone column widths above and the per-orientation font size.
    $nameLimit = $orientation === 'landscape' ? 34 : 26;
    $zoneLimit = $orientation === 'landscape' ? 20 : 15;

    // Display-only status abbreviations to save column width — stored data is untouched.
    $statusAbbr = ['disconnected' => 'disc', 'suspended' => 'susp'];
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Manuscript {{ $period }}</title>
    <style>
        @page { size: a4 {{ $orientation }}; margin: 14px 16px; }
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: {{ $orientation === 'landscape' ? '8.5px' : '7.5px' }};
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
            text-align: left;
            vertical-align: top;
        }
        table.register th.num, table.register td.num { text-align: right; }
        /* Narrowed text columns — content is already truncated in PHP; keep it
           on one line so a stray long value can't wrap and stagger the row. */
        table.register td.clip { white-space: nowrap; }
        /* Blank column the manager fills in by hand after collecting. */
        table.register th.paid, table.register td.paid { border-left: 1px solid #999; }
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

    <table class="register">
        <thead>
            <tr>
                <th style="width: {{ $cols[0] }}%">No</th>
                <th style="width: {{ $cols[1] }}%">Name</th>
                <th style="width: {{ $cols[2] }}%">Code</th>
                <th style="width: {{ $cols[3] }}%">Zone</th>
                <th class="num" style="width: {{ $cols[4] }}%">Bill</th>
                <th class="num" style="width: {{ $cols[5] }}%">Arrears</th>
                <th class="num" style="width: {{ $cols[6] }}%">Credit</th>
                <th class="num" style="width: {{ $cols[7] }}%">Total Bill</th>
                <th class="num paid" style="width: {{ $cols[8] }}%">Paid</th>
                <th style="width: {{ $cols[9] }}%">Status</th>
                <th style="width: {{ $cols[10] }}%">Expiry</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($manuscripts as $index => $manuscript)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="clip">{{ \Illuminate\Support\Str::limit($manuscript->customer?->name ?? '', $nameLimit, '…') }}</td>
                    <td>{{ substr($manuscript->customer?->uuid ?? '', 0, 8) }}</td>
                    <td class="clip">{{ \Illuminate\Support\Str::limit($manuscript->customer?->zone?->name ?? '', $zoneLimit, '…') }}</td>
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
