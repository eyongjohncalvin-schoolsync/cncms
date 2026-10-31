<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Free demo</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:{{ $stage === 'ended' ? '#b91c1c' : '#b45309' }}; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">CNCMS · {{ $companyName }}</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">
                    @if ($forLandlord)
                        Free demo ended
                    @elseif ($stage === 'ended')
                        Your free demo has ended
                    @elseif ($stage === '1')
                        Your free demo ends tomorrow
                    @else
                        Your free demo ends in {{ $stage }} days
                    @endif
                </div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $recipientName }},</p>
                @if ($forLandlord)
                    <p style="margin:0 0 16px;"><strong>{{ $companyName }}</strong>'s free demo ended on <strong>{{ $endsOn }}</strong>. Their workspace is now read-only: they can sign in, view and export, but cannot save anything new.</p>
                    <p style="margin:0;">To let them keep working, open Landlord → Tenants → {{ $companyName }} and choose <strong>Activate full account</strong>, or give them more time with a new end date.</p>
                @else
                    @if ($stage === 'ended')
                        <p style="margin:0 0 16px;">The free demo of CNCMS for <strong>{{ $companyName }}</strong> ended on <strong>{{ $endsOn }}</strong>. The system is now <strong>read-only</strong>: your team can still sign in, view and export everything, but nothing new can be saved.</p>
                        <p style="margin:0 0 16px;">Your data is safe. Nothing has been deleted. Once your account is activated, everyone continues exactly where they stopped, and payments agents recorded on their phones in the meantime sync automatically.</p>
                    @else
                        <p style="margin:0 0 16px;">The free demo of CNCMS for <strong>{{ $companyName }}</strong> ends on <strong>{{ $endsOn }}</strong>. After that day the system becomes read-only: you can still view and export everything, but nothing new can be saved.</p>
                        <p style="margin:0 0 16px;">To keep working without a break, contact us before then to activate your account. Your data stays exactly as it is.</p>
                    @endif
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                        <tr><td style="padding:8px 0; color:#64748b;">WhatsApp / phone</td><td style="padding:8px 0; text-align:right; font-weight:bold;"><a href="https://wa.me/{{ $whatsapp }}" style="color:#0f172a;">{{ $phone }}</a></td></tr>
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Email</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;"><a href="mailto:{{ $email }}" style="color:#0f172a;">{{ $email }}</a></td></tr>
                    </table>
                    <p style="margin:16px 0 0; font-size:13px; color:#64748b;">{{ $company }} — the team behind CNCMS.</p>
                @endif
            </td>
        </tr>
    </table>
</body>
</html>
