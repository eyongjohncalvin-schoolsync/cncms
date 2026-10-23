<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>New workspace waiting for approval</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#4f46e5; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · Landlord</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">New workspace waiting for approval</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $landlordName }},</p>
                <p style="margin:0 0 16px;">A company has just registered on CNCMS. They can't use their workspace until a landlord approves it.</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">Company</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $workspaceName }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Workspace</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $workspaceSlug }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Owner</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $ownerName }} &lt;{{ $ownerEmail }}&gt;</td></tr>
                    @if ($location)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Location</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $location }}</td></tr>
                    @endif
                    @if ($phone)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Phone</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $phone }}</td></tr>
                    @endif
                </table>
                <p style="margin:20px 0 0; text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block; background:#4f46e5; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:bold;">Review and approve</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
