<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:{{ in_array($kind, ['two_factor_disabled', 'two_factor_reset'], true) ? '#b45309' : '#1d4ed8' }}; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · Security notice</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">{{ $headline }}</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                @if ($kind === 'password_changed')
                    <p style="margin:0 0 16px;">The password for your CNCMS account was just changed. Other devices signed in to your account were signed out.</p>
                @elseif ($kind === 'email_changed')
                    <p style="margin:0 0 16px;">The email address on your CNCMS account was changed @if ($newEmail) to <strong>{{ $newEmail }}</strong>@endif. Security notices and sign-in codes now go to the new address.</p>
                @elseif ($kind === 'two_factor_enabled')
                    <p style="margin:0 0 16px;">Two-factor authentication is now on. Signing in will ask for a code from your authenticator app as well as your password.</p>
                @elseif ($kind === 'two_factor_disabled')
                    <p style="margin:0 0 16px;">Two-factor authentication was turned off. Signing in now needs only your password.</p>
                @elseif ($kind === 'recovery_codes_regenerated')
                    <p style="margin:0 0 16px;">New two-factor recovery codes were generated. Your old recovery codes no longer work.</p>
                @elseif ($kind === 'password_reset')
                    <p style="margin:0 0 16px;">The password for your CNCMS account was reset with a code sent to this email address. Every browser and device signed in to your account was signed out.</p>
                @elseif ($kind === 'two_factor_reset')
                    <p style="margin:0 0 16px;">CNCMS support turned off two-factor authentication on your account and signed you out everywhere. Signing in now needs only your password. Turn two-factor back on from My account as soon as you can.</p>
                @endif
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">When</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $occurredAt }} (Cameroon time)</td></tr>
                    @if ($ipAddress)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">IP address</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ $ipAddress }}</td></tr>
                    @endif
                </table>
                <p style="margin:16px 0 0; padding:12px; background:#fef2f2; border-radius:8px; font-size:14px; color:#991b1b;">Was this not you? Contact your administrator straight away so they can secure your account.</p>
            </td>
        </tr>
    </table>
</body>
</html>
