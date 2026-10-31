<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Security alert</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#b91c1c; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · Landlord</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">Possible password attack</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                @if ($blockedUntil)
                    <p style="margin:0 0 16px;">IP address <strong>{{ $ipAddress }}</strong> typed too many wrong passwords within an hour ({{ $accounts }} {{ $accounts === 1 ? 'account' : 'different accounts' }}). Password sign-in from that address is <strong>blocked until {{ $blockedUntil }}</strong> (Cameroon time).</p>
                    <p style="margin:0;">If it is your own staff sharing one network (for example agents on the same mobile data), unblock it in Landlord → Security. Anyone there can still sign in with an email code meanwhile.</p>
                @else
                    <p style="margin:0 0 16px;">One device, IP address <strong>{{ $ipAddress }}</strong>, typed wrong passwords on <strong>{{ $accounts }} different accounts</strong> within an hour. This can be someone guessing passwords across companies.</p>
                    <p style="margin:0;">Each account is blocked automatically after repeated wrong passwords, and the address itself if it keeps going. See who was targeted in Landlord → Security.</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
