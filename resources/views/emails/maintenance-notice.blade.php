<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Planned maintenance</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:{{ $kind === 'cancelled' ? '#15803d' : '#b45309' }}; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">
                    @if ($kind === 'cancelled')
                        Planned maintenance cancelled
                    @elseif ($kind === 'changed')
                        Planned maintenance — new end time
                    @else
                        Planned maintenance
                    @endif
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                @if ($kind === 'cancelled')
                    <p style="margin:0 0 16px;">The maintenance planned for <strong>{{ $startsAt }}</strong> has been cancelled. You can keep working as normal.</p>
                @else
                    <p style="margin:0 0 16px;">CNCMS will be unavailable during the times below (Cameroon time). Please save your work and finish any payment you are recording before it starts.</p>
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                        <tr><td style="padding:8px 0; color:#64748b;">From</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $startsAt }}</td></tr>
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Until</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ $endsAt }}</td></tr>
                    </table>
                    @if ($message)
                        <p style="margin:16px 0 0; padding:12px; background:#f8fafc; border-radius:8px;">{{ $message }}</p>
                    @endif
                    <p style="margin:16px 0 0; font-size:13px; color:#64748b;">Field agents: the mobile app keeps working offline. Payments you record during the maintenance are saved on the phone and sync automatically afterwards.</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
