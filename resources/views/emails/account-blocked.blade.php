<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Password sign-in blocked</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#b91c1c; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · Security</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">Password sign-in blocked</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                <p style="margin:0 0 16px;">Someone typed a wrong password for your CNCMS account <strong>{{ $failures }} times</strong> within an hour. To protect you, password sign-in is blocked until <strong>{{ $blockedUntil }}</strong> (Cameroon time).</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">Last attempt from</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $ipAddress }}</td></tr>
                </table>
                <p style="margin:16px 0 0;"><strong>Was it you?</strong> Wait until the time above, or sign in now with a code sent to this email ("Sign in with an email code" on the login page).</p>
                <p style="margin:12px 0 0;"><strong>Not you?</strong> Sign in with an email code and change your password in My account. Turning on two-step sign-in there makes your account much safer.</p>
            </td>
        </tr>
    </table>
</body>
</html>
