<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="x-apple-disable-message-reformatting">
    <title>{{ $brandName }} verification code</title>
</head>
<body style="margin:0; padding:0; background:#f3f6fa; color:#172b4d; font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f3f6fa;">
    <tr>
        <td align="center" style="padding:32px 16px;">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:600px; background:#ffffff; border:1px solid #e3eaf2; border-radius:16px; overflow:hidden;">
                <tr>
                    <td style="padding:24px 32px; background:#102a43;">
                        <table role="presentation" cellspacing="0" cellpadding="0" border="0">
                            <tr>
                                <td valign="middle">
                                    <img src="https://scrapifyauctions.com/scrapify-auction-app-icon.png" width="48" height="48" alt="Scrapify Auctions" style="display:block; width:48px; height:48px; border:0; border-radius:12px;">
                                </td>
                                <td valign="middle" style="padding-left:14px; color:#ffffff; font-size:22px; font-weight:700; letter-spacing:-0.3px;">
                                    Scrapify <span style="color:#ff6b2c;">Auction</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:36px 40px 32px;">
                        <p style="margin:0 0 8px; color:#102a43; font-size:24px; line-height:32px; font-weight:700;">Verify your email</p>
                        <p style="margin:0 0 24px; color:#52667f; font-size:15px; line-height:24px;">{{ $messageBody }}</p>
                        <p style="margin:0 0 10px; color:#52667f; font-size:14px; line-height:20px;">Your verification code</p>
                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin:0 0 22px;">
                            <tr>
                                <td align="center" style="padding:18px 12px; background:#fff1e9; border:1px solid #ffd6c2; border-radius:12px; color:#e85d20; font-size:32px; line-height:40px; font-weight:700; letter-spacing:9px;">
                                    {{ $code }}
                                </td>
                            </tr>
                        </table>
                        @if ($showExpiry)
                            <p style="margin:0 0 22px; color:#52667f; font-size:14px; line-height:22px;">This code expires in <strong style="color:#102a43;">{{ $minutes }} minutes</strong>.</p>
                        @endif
                        <p style="margin:0; padding-top:20px; border-top:1px solid #e3eaf2; color:#7a8ca3; font-size:13px; line-height:21px;">If you did not request this code, you can safely ignore this email. Never share your verification code with anyone.</p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 40px; background:#f8fafc; color:#7a8ca3; font-size:12px; line-height:18px;">
                        © {{ date('Y') }} {{ $brandName }}. All rights reserved.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
