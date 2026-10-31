<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>A database backup was downloaded</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#b45309; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · Security notice</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">A database backup was downloaded</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                <p style="margin:0 0 16px;"><strong>{{ $downloadedBy }}</strong> downloaded a full database backup from the landlord area. It holds every workspace's data, locked with the password they chose.</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">File</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $fileName }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">When</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ $occurredAt }} (Cameroon time)</td></tr>
                    @if ($ipAddress)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">IP address</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ $ipAddress }}</td></tr>
                    @endif
                </table>
                <p style="margin:16px 0 0; padding:12px; background:#fef2f2; border-radius:8px; font-size:14px; color:#991b1b;">Not expected? Revoke that person's landlord access and change their password straight away.</p>
            </td>
        </tr>
    </table>
</body>
</html>
