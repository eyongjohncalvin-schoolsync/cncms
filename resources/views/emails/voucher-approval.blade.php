<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Voucher {{ $voucher->number }} needs your approval</title>
</head>
<body style="margin:0; padding:24px 12px; background:#f1f5f9; font-family:Helvetica, Arial, sans-serif; color:#0f172a;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden;">
        <tr>
            <td style="background:#b45309; color:#ffffff; padding:20px 24px;">
                <div style="font-size:13px; opacity:.85;">{{ $companyName }}</div>
                <div style="font-size:20px; font-weight:bold; margin-top:4px;">A payout needs your approval</div>
            </td>
        </tr>
        <tr>
            <td style="padding:20px 24px; font-size:15px; line-height:1.5;">
                <p style="margin:0 0 12px;">Hello {{ $approverName }},</p>
                <p style="margin:0 0 16px;">
                    @if (in_array('above_single_limit', $reasons, true) && in_array('above_daily_limit', $reasons, true))
                        This voucher is above the single-voucher limit and takes today's payouts above the daily limit,
                    @elseif (in_array('above_daily_limit', $reasons, true))
                        This voucher takes the day's payouts above the daily limit,
                    @else
                        This voucher is above the single-voucher limit,
                    @endif
                    so it can only be paid once every stakeholder has approved it.
                </p>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px; border-top:1px solid #e2e8f0;">
                    <tr><td style="padding:8px 0; color:#64748b;">Voucher</td><td style="padding:8px 0; text-align:right; font-weight:bold;">{{ $voucher->number }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Amount</td><td style="padding:8px 0; text-align:right; font-weight:bold; border-top:1px solid #e2e8f0;">{{ number_format((float) $voucher->amount, 0, '.', ',') }} FCFA</td></tr>
                    <tr><td colspan="2" style="padding:0 0 8px; text-align:right; font-size:12px; color:#64748b; font-style:italic;">{{ $amountWords }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Pay to</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $voucher->payee_name }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">For</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $voucher->purpose }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Requested by</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $voucher->requestedBy?->name }}</td></tr>
                    <tr><td style="padding:8px 0; color:#64748b; border-top:1px solid #e2e8f0;">Needed on</td><td style="padding:8px 0; text-align:right; border-top:1px solid #e2e8f0;">{{ $voucher->needed_on?->format('d M Y') }}</td></tr>
                </table>
                <p style="margin:20px 0 0; text-align:center;">
                    <a href="{{ $url }}" style="display:inline-block; background:#b45309; color:#ffffff; text-decoration:none; padding:12px 20px; border-radius:8px; font-weight:bold;">Review and approve or reject</a>
                </p>
            </td>
        </tr>
        <tr>
            <td style="padding:14px 24px; background:#f8fafc; font-size:12px; color:#94a3b8; text-align:center;">
                Sent by {{ $companyName }} via CNCMS. Sign in to approve — never approve by replying to this email.
            </td>
        </tr>
    </table>
</body>
</html>
