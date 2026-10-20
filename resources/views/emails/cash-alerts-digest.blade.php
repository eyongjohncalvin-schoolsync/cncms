<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cash controls digest</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#b91c1c; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">{{ $companyName }}</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">Cash controls — today's alerts</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:14px; line-height:1.5;">
                <p style="margin:0 0 16px;">Hello {{ $recipientName }}, these need someone to act:</p>
                @foreach ($alerts as $alert)
                    <div style="margin:0 0 16px;">
                        <div style="font-weight:bold; color:{{ $alert['severity'] === 'danger' ? '#b91c1c' : '#b45309' }};">{{ $alert['title'] }} ({{ count($alert['items']) }})</div>
                        <div style="font-size:12px; color:#64748b; margin-bottom:4px;">{{ $alert['advice'] }}</div>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;">
                            @foreach (array_slice($alert['items'], 0, 10) as $item)
                                <tr>
                                    <td style="padding:4px 0; border-top:1px solid #e2e8f0;">{{ $item['label'] }}<br><span style="color:#64748b; font-size:12px;">{{ $item['detail'] }}</span></td>
                                    <td style="padding:4px 0; border-top:1px solid #e2e8f0; text-align:right; white-space:nowrap;">@if ($item['amount'] !== null){{ number_format((float) $item['amount'], 0, '.', ',') }} FCFA @endif</td>
                                </tr>
                            @endforeach
                            @if (count($alert['items']) > 10)
                                <tr><td colspan="2" style="padding:4px 0; color:#64748b; font-size:12px;">…and {{ count($alert['items']) - 10 }} more.</td></tr>
                            @endif
                        </table>
                    </div>
                @endforeach
                <p style="margin:20px 0 0; text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block; background:#b91c1c; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:bold;">Open the cash alerts</a>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 24px; background:#f8fafc; font-size:12px; color:#94a3b8; text-align:center;">Sent every morning by CNCMS while there is something to act on.</td>
        </tr>
    </table>
</body>
</html>
