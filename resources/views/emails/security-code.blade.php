<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Your CNCMS code</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#1d4ed8; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">
                    @if ($isEmailChange)
                        Confirm your new email address
                    @elseif ($isPasswordReset ?? false)
                        Reset your password
                    @else
                        Your sign-in code
                    @endif
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                <p style="margin:0 0 16px;">
                    @if ($isEmailChange)
                        Enter this code in CNCMS to make this address the email for your account:
                    @elseif ($isPasswordReset ?? false)
                        Someone asked to reset the password for your CNCMS account. Enter this code in CNCMS to choose a new password:
                    @else
                        Enter this code in CNCMS to finish signing in:
                    @endif
                </p>
                <p style="margin:0 0 16px; text-align:center; font-size:32px; font-weight:bold; letter-spacing:8px; font-family:monospace; background:#f8fafc; border-radius:8px; padding:12px;">{{ $code }}</p>
                <p style="margin:0 0 12px; font-size:14px; color:#475569;">The code expires in {{ $expiresInMinutes }} minutes and works only once.</p>
                <p style="margin:0; font-size:13px; color:#64748b;">Did not ask for this? Ignore this email: nobody can use the code without access to your inbox. Never share it with anyone, not even someone claiming to be from CNCMS.</p>
            </td>
        </tr>
    </table>
</body>
</html>
