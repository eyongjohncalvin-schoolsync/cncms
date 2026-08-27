<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Monthly Report {{ $period }}</title>
    <style>
        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #111;
            margin: 0;
            padding: 16px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .subtitle {
            font-size: 11px;
            margin-bottom: 12px;
            color: #333;
        }
        .section-title {
            font-size: 12px;
            font-weight: bold;
            margin: 14px 0 4px;
            padding-bottom: 2px;
            border-bottom: 1px solid #999;
        }
        .hint {
            font-size: 8px;
            color: #666;
            font-weight: normal;
        }
        table.summary {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 6px;
        }
        table.summary td {
            padding: 5px 8px;
            border: 1px solid #999;
            text-align: center;
        }
        table.summary td.label {
            font-weight: bold;
            background: #eee;
        }
        .empty-state {
            padding: 10px;
            border: 1px dashed #999;
            color: #555;
            text-align: center;
        }
        table.rows {
            width: 100%;
            border-collapse: collapse;
        }
        table.rows th,
        table.rows td {
            border: 1px solid #999;
            padding: 3px 6px;
            text-align: left;
        }
        table.rows th {
            background: #ddd;
            font-weight: bold;
        }
        table.rows td.num,
        table.rows th.num {
            text-align: right;
        }
        .footer {
            margin-top: 18px;
            padding-top: 6px;
            border-top: 1px solid #ccc;
            font-size: 8px;
            color: #555;
        }
    </style>
</head>
<body>
    <div class="title">Monthly Report — {{ $label }}</div>
    <div class="subtitle">Period: {{ $period }} &nbsp;|&nbsp; Scope: {{ $branch_label }}</div>

    <div class="section-title">Collections (cash received)</div>
    <p class="hint">Bucketed by payment date, all verification statuses shown. Not the same figure as the billing/P&amp;L blocks below — see the labels.</p>
    <table class="summary">
        <tr>
            <td class="label">Verified</td>
            <td class="label">Pending</td>
            <td class="label">Rejected</td>
            <td class="label">Total</td>
            <td class="label">Payments</td>
        </tr>
        <tr>
            <td>{{ number_format((float) $collections_cash_received['verified'], 2) }}</td>
            <td>{{ number_format((float) $collections_cash_received['pending'], 2) }}</td>
            <td>{{ number_format((float) $collections_cash_received['rejected'], 2) }}</td>
            <td>{{ number_format((float) $collections_cash_received['total'], 2) }}</td>
            <td>{{ $collections_cash_received['count'] }}</td>
        </tr>
    </table>

    <div class="section-title">Arrears Adjustments (written off)</div>
    <p class="hint">Approved, decrease-direction ledger corrections targeting this period — not a payment, never counted as cash collected above.</p>
    <table class="summary">
        <tr>
            <td class="label">Total Written Off</td>
            <td class="label">Adjustments</td>
        </tr>
        <tr>
            <td>{{ number_format((float) $arrears_adjustments_written_off['total'], 2) }}</td>
            <td>{{ $arrears_adjustments_written_off['count'] }}</td>
        </tr>
    </table>

    <div class="section-title">Billing (ledger) <span class="hint">— manuscript:calculate run, verified payments only</span></div>
    @if ($billing_ledger === null)
        <div class="empty-state">Billing run not yet executed for this period.</div>
    @else
        <p class="hint">Run at: {{ \Illuminate\Support\Carbon::parse($billing_ledger['ran_at'])->format('Y-m-d H:i') }} UTC</p>
        <table class="summary">
            <tr>
                <td class="label">Customers Processed</td>
                <td class="label">Frozen</td>
                <td class="label">Total Bill</td>
                <td class="label">Total Arrears</td>
                <td class="label">Total Credit</td>
                <td class="label">Errors</td>
            </tr>
            <tr>
                <td>{{ $billing_ledger['customers_processed'] ?? '—' }}</td>
                <td>{{ $billing_ledger['frozen_customers'] ?? '—' }}</td>
                <td>{{ isset($billing_ledger['total_bill_sum']) ? number_format((float) $billing_ledger['total_bill_sum'], 2) : '—' }}</td>
                <td>{{ isset($billing_ledger['total_arrears_sum']) ? number_format((float) $billing_ledger['total_arrears_sum'], 2) : '—' }}</td>
                <td>{{ isset($billing_ledger['total_credit_sum']) ? number_format((float) $billing_ledger['total_credit_sum'], 2) : '—' }}</td>
                <td>{{ $billing_ledger['errors'] ?? '—' }}</td>
            </tr>
        </table>
    @endif

    <div class="section-title">Collection Health</div>
    <table class="summary">
        <tr>
            <td class="label">Collection Rate</td>
            <td class="label">Total Collected</td>
            <td class="label">Total Billed</td>
            <td class="label">1x Arrears</td>
            <td class="label">2x Arrears</td>
            <td class="label">3x+ Arrears</td>
        </tr>
        <tr>
            <td>{{ $collection_health['collection_rate'] }}%</td>
            <td>{{ number_format((float) $collection_health['total_collected'], 2) }}</td>
            <td>{{ number_format((float) $collection_health['total_bill'], 2) }}</td>
            <td>{{ $collection_health['arrears_aging']['1x'] }}</td>
            <td>{{ $collection_health['arrears_aging']['2x'] }}</td>
            <td>{{ $collection_health['arrears_aging']['3x_plus'] }}</td>
        </tr>
    </table>

    @if (isset($pnl))
        <div class="section-title">Profit &amp; Loss</div>
        <table class="summary">
            <tr>
                <td class="label">Verified Income</td>
                <td class="label">Expenses</td>
                <td class="label">Net</td>
                <td class="label">Margin</td>
            </tr>
            <tr>
                <td>{{ number_format((float) $pnl['income']['verified'], 2) }}</td>
                <td>{{ number_format((float) $pnl['expenses']['total'], 2) }}</td>
                <td>{{ number_format((float) $pnl['pnl']['net'], 2) }}</td>
                <td>{{ $pnl['pnl']['margin_pct'] }}%</td>
            </tr>
        </table>
    @endif

    <div class="section-title">Day-by-Day Trend</div>
    @if (empty($trend))
        <div class="empty-state">No payments recorded this period.</div>
    @else
        <table class="rows">
            <thead>
                <tr>
                    <th>Date</th>
                    <th class="num">Verified Collections</th>
                    <th class="num">Payments</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($trend as $row)
                    <tr>
                        <td>{{ $row['date'] }}</td>
                        <td class="num">{{ number_format((float) $row['verified'], 2) }}</td>
                        <td class="num">{{ $row['payment_count'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Generated {{ $generated_at->format('Y-m-d H:i') }} WAT &middot; Branch: {{ $branch_label }} &middot; Period bounds: {{ $period }}
    </div>
</body>
</html>
