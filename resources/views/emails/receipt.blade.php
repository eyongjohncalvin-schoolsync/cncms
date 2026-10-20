<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment receipt {{ $receiptNumber }}</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#15803d; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">{{ $companyName }}</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">Payment received — thank you!</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $customerName ?? 'there' }},</p>
                <p style="margin:0 0 16px;">We have received your payment. Your receipt is attached to this email as a PDF.</p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">Receipt</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $receiptNumber }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Amount paid</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ number_format((float) $amount, 0, '.', ',') }} FCFA</td></tr>
                    @if ($issuedAt)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Date</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $issuedAt->format('d M Y, H:i') }}</td></tr>
                    @endif
                    @if ($balance && (float) $balance['remaining'] > 0)
                        <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Still due</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ number_format((float) $balance['remaining'], 0, '.', ',') }} FCFA</td></tr>
                    @endif
                </table>
                <p style="margin:16px 0 0; font-size:13px; color:#64748b;">
                    You can check this receipt is genuine at any time: <a href="{{ $verifyUrl }}" style="color:#15803d;">verify receipt {{ $receiptNumber }}</a>@if (! empty($securityCode)) — security code <strong>{{ $securityCode }}</strong>@endif.
                    <br>The amount above must match the receipt you were given. If it doesn't, or you did not make this payment, contact {{ $companyName }} straight away.
                    @if ($momoNumber)<br>Pay by Mobile Money: {{ $momoNumber }}.@endif
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 24px; background:#f8fafc; font-size:12px; color:#94a3b8; text-align:center;">
                Sent by {{ $companyName }} via CNCMS. Reply to this email to contact {{ $companyName }}.
            </td>
        </tr>
    </table>
</body>
</html>
