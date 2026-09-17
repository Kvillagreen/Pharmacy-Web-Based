<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} Password Reset</title>
</head>
<body style="margin:0;padding:0;background:#f4f7fb;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f7fb;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:620px;background:#ffffff;border:1px solid #e2e8f0;border-radius:20px;overflow:hidden;">
                    <tr>
                        <td style="background:linear-gradient(135deg,#ecfdf5 0%,#f8fafc 100%);padding:32px 36px 24px;">
                            <p style="margin:0 0 10px;font-size:12px;letter-spacing:0.18em;text-transform:uppercase;font-weight:700;color:#047857;">
                                {{ $appName }}
                            </p>
                            <h1 style="margin:0;font-size:28px;line-height:1.25;color:#0f172a;">Password Reset Request</h1>
                            <p style="margin:14px 0 0;font-size:15px;line-height:1.7;color:#475569;">
                                Hello {{ $recipientName }},
                            </p>
                            <p style="margin:12px 0 0;font-size:15px;line-height:1.7;color:#475569;">
                                We received a request to reset your password. Use the verification code below to continue.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:30px 36px;">
                            <div style="border:1px dashed #a7f3d0;border-radius:18px;background:#f0fdf4;padding:24px;text-align:center;">
                                <p style="margin:0 0 10px;font-size:13px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#047857;">
                                    Verification Code
                                </p>
                                <p style="margin:0;font-size:34px;font-weight:700;letter-spacing:0.24em;color:#064e3b;">
                                    {{ $code }}
                                </p>
                            </div>

                            <p style="margin:24px 0 0;font-size:14px;line-height:1.7;color:#475569;">
                                This code will expire on <strong style="color:#0f172a;">{{ $expiresAtText }}</strong>.
                            </p>
                            <p style="margin:10px 0 0;font-size:14px;line-height:1.7;color:#475569;">
                                If you did not request this change, you can safely ignore this email. Your password will remain unchanged.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="border-top:1px solid #e2e8f0;padding:20px 36px 28px;background:#f8fafc;">
                            <p style="margin:0;font-size:13px;line-height:1.7;color:#64748b;">
                                This is an automated security message from {{ $appName }}.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
