@php
    /**
     * Customer record export — rendered by
     * App\Http\Controllers\CustomerRecordExportController::pdf() from
     * App\Services\CustomerRecordExportService::gather(). Company header
     * matched to resources/views/pdf/bill.blade.php.
     *
     * @var array $data     the gather() result
     * @var ?\App\Models\Company $company
     */
    $meta = $data['meta'] ?? [];
    $profile = $data['profile'] ?? [];
    $payments = $data['payments'] ?? [];
    $manuscripts = $data['manuscripts'] ?? [];
    $adjustments = $data['arrears_adjustments'] ?? [];
    $messages = $data['messages'] ?? [];
    $complaints = $data['complaints'] ?? [];
    $statusHistory = $data['status_history'] ?? [];
    $audit = $data['audit_trail'] ?? ['entries' => [], 'truncated' => false, 'cap' => 0, 'total' => 0];

    $money = fn ($v) => number_format((float) $v, 2);
    $dt = function ($v) {
        return $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y, H:i') : '—';
    };
    $d = function ($v) {
        return $v ? \Illuminate\Support\Carbon::parse($v)->format('d M Y') : '—';
    };
    $show = fn ($v) => ($v === null || $v === '') ? '—' : $v;
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Customer Record — {{ $meta['customer_name'] ?? '' }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #111; margin: 0; padding: 22px; }
        .letterhead { width: 100%; margin-bottom: 8px; }
        .letterhead .logo { max-height: 54px; max-width: 150px; }
        .company-name { font-size: 13px; font-weight: bold; }
        .company-meta { font-size: 8.5px; color: #444; }
        .doc-title {
            font-size: 15px; font-weight: bold; letter-spacing: 1px;
            margin: 12px 0 4px; border-top: 2px solid #111; border-bottom: 2px solid #111;
            padding: 5px 0; text-align: center;
        }
        h2 {
            font-size: 12px; margin: 18px 0 6px; border-bottom: 1px solid #333; padding-bottom: 3px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        table.kv td { padding: 2px 4px; vertical-align: top; }
        table.kv td.label { width: 170px; font-weight: bold; color: #333; }
        table.grid th, table.grid td {
            border: 1px solid #bbb; padding: 3px 5px; text-align: left; font-size: 8.5px; vertical-align: top;
        }
        table.grid th { background: #eee; }
        td.num, th.num { text-align: right; }
        .muted { color: #666; font-style: italic; }
        .section { page-break-before: always; }
        .note { font-size: 8.5px; color: #444; margin-bottom: 6px; }
        .empty { font-style: italic; color: #777; margin: 4px 0 10px; }
    </style>
</head>
<body>
    @if ($company?->logoDataUri())
        <table class="letterhead"><tr><td><img src="{{ $company->logoDataUri() }}" class="logo" alt="logo"></td></tr></table>
    @endif

    <div class="company-name">{{ $company?->name }}</div>
    <div class="company-meta">
        {{ $company?->location }}@if($company?->head_office) &middot; {{ $company->head_office }}@endif<br>
        @if($company?->tech_number)Tel: {{ $company->tech_number }}@endif
        @if($company?->rccm_number) &middot; RCCM: {{ $company->rccm_number }}@endif
        @if($company?->niu) &middot; NIU: {{ $company->niu }}@endif
    </div>

    <div class="doc-title">CUSTOMER RECORD — {{ strtoupper($meta['customer_name'] ?? '') }}</div>

    <div class="note">
        {{ $meta['note'] ?? '' }}<br>
        Generated {{ $dt($meta['generated_at'] ?? null) }} by {{ $show($meta['generated_by'] ?? null) }}
        &middot; Customer UUID: {{ $meta['customer_uuid'] ?? '' }}
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <h2>Profile</h2>
    <table class="kv">
        @foreach ($profile as $field => $value)
            <tr>
                <td class="label">{{ ucwords(str_replace('_', ' ', $field)) }}</td>
                <td>
                    @if (is_bool($value)) {{ $value ? 'Yes' : 'No' }}
                    @else {{ $show($value) }}
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Payments ({{ count($payments) }})</h2>
    @if (count($payments) === 0)
        <p class="empty">No payments recorded for this customer.</p>
    @else
        <table class="grid">
            <tr>
                <th>Recorded</th><th class="num">Amount</th><th class="num">Credit</th>
                <th>Method</th><th>Verify</th><th>Processed</th><th>Expires</th>
                <th>MoMo Ref / Status</th><th>Verified By</th><th>Receipt</th>
            </tr>
            @foreach ($payments as $p)
                <tr>
                    <td>{{ $dt($p['created_at'] ?? null) }}</td>
                    <td class="num">{{ $money($p['amount'] ?? 0) }}</td>
                    <td class="num">{{ $money($p['credit'] ?? 0) }}</td>
                    <td>{{ $show($p['method'] ?? null) }}@if(!empty($p['months'])) ({{ $p['months'] }}m)@endif</td>
                    <td>{{ $show($p['verification_status'] ?? null) }}</td>
                    <td>{{ $p['processed_period'] ?? ($p['processed_at'] ? $d($p['processed_at']) : '—') }}</td>
                    <td>{{ $d($p['expiration_date'] ?? null) }}</td>
                    <td>{{ $show($p['verification']['momo_ref'] ?? null) }}
                        @if(!empty($p['verification']['momo_status'])) / {{ $p['verification']['momo_status'] }}@endif</td>
                    <td>{{ $show($p['verification']['verified_by'] ?? null) }}
                        @if(!empty($p['verification']['verified_at']))<br><span class="muted">{{ $dt($p['verification']['verified_at']) }}</span>@endif</td>
                    <td>{{ $show($p['receipt']['number'] ?? null) }}
                        @if(!empty($p['receipt']['status'])) ({{ $p['receipt']['status'] }})@endif</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Manuscript History ({{ count($manuscripts) }})</h2>
    @if (count($manuscripts) === 0)
        <p class="empty">No manuscripts calculated for this customer yet.</p>
    @else
        <table class="grid">
            <tr>
                <th>Period</th><th class="num">Bill</th><th class="num">Arrears</th>
                <th class="num">Credit</th><th class="num">Total Bill</th>
                <th>Coverage Through</th><th>Command Run</th><th>Calculated</th>
            </tr>
            @foreach ($manuscripts as $m)
                <tr>
                    <td>{{ $show($m['period'] ?? null) }}</td>
                    <td class="num">{{ $money($m['bill'] ?? 0) }}</td>
                    <td class="num">{{ $money($m['total_arrears'] ?? 0) }}</td>
                    <td class="num">{{ $money($m['credit'] ?? 0) }}</td>
                    <td class="num">{{ $money($m['total_bill'] ?? 0) }}</td>
                    <td>{{ $show($m['coverage_through'] ?? null) }}</td>
                    <td>{{ $show($m['command_run'] ?? null) }}@if(!empty($m['command_run_id'])) #{{ $m['command_run_id'] }}@endif</td>
                    <td>{{ $dt($m['created_at'] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Arrears Adjustments ({{ count($adjustments) }})</h2>
    @if (count($adjustments) === 0)
        <p class="empty">No arrears adjustments requested for this customer.</p>
    @else
        <table class="grid">
            <tr>
                <th>Requested</th><th>Direction</th><th>Target</th><th class="num">Amount</th>
                <th>Period</th><th>Reason</th><th>Status</th><th>Requested By</th><th>Approved By</th>
            </tr>
            @foreach ($adjustments as $a)
                <tr>
                    <td>{{ $dt($a['requested_at'] ?? null) }}</td>
                    <td>{{ $show($a['direction'] ?? null) }}</td>
                    <td>{{ $show($a['target'] ?? null) }}</td>
                    <td class="num">{{ $money($a['amount'] ?? 0) }}</td>
                    <td>{{ $show($a['target_period'] ?? null) }}</td>
                    <td>{{ $show($a['reason_category'] ?? null) }}@if(!empty($a['reason_note']))<br><span class="muted">{{ $a['reason_note'] }}</span>@endif</td>
                    <td>{{ $show($a['status'] ?? null) }}@if(!empty($a['rejection_reason']))<br><span class="muted">{{ $a['rejection_reason'] }}</span>@endif</td>
                    <td>{{ $show($a['requested_by'] ?? null) }}</td>
                    <td>{{ $show($a['approved_by'] ?? null) }}@if(!empty($a['second_approved_by']))<br>{{ $a['second_approved_by'] }}@endif</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Status History ({{ count($statusHistory) }})</h2>
    @if (count($statusHistory) === 0)
        <p class="empty">No status changes recorded. (Derived from the audit trail — there is no dedicated status-history table.)</p>
    @else
        <p class="note">Derived from <code>audit_logs</code> — there is no dedicated status-history table.</p>
        <table class="grid">
            <tr><th>When</th><th>From</th><th>To</th><th>Reason</th><th>Note</th><th>Changed By</th><th>IP</th></tr>
            @foreach ($statusHistory as $s)
                <tr>
                    <td>{{ $dt($s['changed_at'] ?? null) }}</td>
                    <td>{{ $show($s['from'] ?? null) }}</td>
                    <td>{{ $show($s['to'] ?? null) }}</td>
                    <td>{{ $show($s['reason'] ?? null) }}</td>
                    <td>{{ $show($s['note'] ?? null) }}</td>
                    <td>{{ $show($s['changed_by'] ?? null) }}</td>
                    <td>{{ $show($s['ip_address'] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Messages ({{ count($messages) }})</h2>
    @if (count($messages) === 0)
        <p class="empty">No messages logged for this customer.</p>
    @else
        <table class="grid">
            <tr><th>Sent</th><th>Type</th><th>Channel</th><th>Status</th><th>SID</th><th>Content</th></tr>
            @foreach ($messages as $m)
                <tr>
                    <td>{{ $dt($m['created_at'] ?? null) }}</td>
                    <td>{{ $show($m['type'] ?? null) }}</td>
                    <td>{{ $show($m['channel'] ?? null) }}</td>
                    <td>{{ $show($m['status'] ?? null) }}</td>
                    <td>{{ $show($m['sid'] ?? null) }}</td>
                    <td>{{ $show($m['content'] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Complaints ({{ count($complaints) }})</h2>
    @if (count($complaints) === 0)
        <p class="empty">No complaints filed for this customer.</p>
    @else
        <table class="grid">
            <tr>
                <th>Submitted</th><th>Category</th><th>Title</th><th>Status</th><th>Urgent</th>
                <th>Esc. Lvl</th><th>Assigned To</th><th>Resolved By</th><th>Resolution</th>
            </tr>
            @foreach ($complaints as $c)
                <tr>
                    <td>{{ $dt($c['created_at'] ?? null) }}</td>
                    <td>{{ $show($c['category'] ?? null) }}</td>
                    <td>{{ $show($c['title'] ?? null) }}<br><span class="muted">{{ $show($c['description'] ?? null) }}</span></td>
                    <td>{{ $show($c['status'] ?? null) }}</td>
                    <td>{{ !empty($c['urgent']) ? 'Yes' : 'No' }}</td>
                    <td>{{ $c['escalation_level'] ?? 0 }}@if(!empty($c['escalated_at']))<br><span class="muted">{{ $dt($c['escalated_at']) }}</span>@endif</td>
                    <td>{{ $show($c['assigned_to'] ?? null) }}</td>
                    <td>{{ $show($c['resolved_by'] ?? null) }}</td>
                    <td>{{ $show($c['resolution_notes'] ?? null) }}</td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>

    {{-- ---------------------------------------------------------------- --}}
    <div class="section">
    <h2>Audit Trail ({{ $audit['total'] ?? 0 }} entries)</h2>
    @if (($audit['truncated'] ?? false))
        <p class="note">Showing the {{ $audit['cap'] }} most recent of {{ $audit['total'] }} audit entries. Older entries are omitted from this export.</p>
    @endif
    @if (count($audit['entries'] ?? []) === 0)
        <p class="empty">No audit entries for this customer or its payments / manuscripts.</p>
    @else
        <table class="grid">
            <tr><th>When</th><th>Table</th><th>Action</th><th>User</th><th>IP</th><th>Changes (field: old &rarr; new)</th></tr>
            @foreach ($audit['entries'] as $e)
                <tr>
                    <td>{{ $dt($e['created_at'] ?? null) }}</td>
                    <td>{{ $show($e['table'] ?? null) }}</td>
                    <td>{{ $show($e['action'] ?? null) }}</td>
                    <td>{{ $show($e['user'] ?? null) }}</td>
                    <td>{{ $show($e['ip_address'] ?? null) }}</td>
                    <td>
                        @foreach (($e['changes'] ?? []) as $chg)
                            <div>{{ $chg['field'] }}: <span class="muted">{{ is_array($chg['old']) ? json_encode($chg['old']) : ($chg['old'] ?? '∅') }}</span>
                            &rarr; {{ is_array($chg['new']) ? json_encode($chg['new']) : ($chg['new'] ?? '∅') }}</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
    </div>
</body>
</html>
