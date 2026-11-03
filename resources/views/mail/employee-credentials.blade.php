{{-- Sign-in details email. Table layout + inline styles: email clients ignore most CSS. Palette from the app (dark). --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>{{ $isReset ? "Your new {$brand} password" : "Welcome to {$brand}" }}</title>
</head>
<body style="margin:0;padding:0;background-color:#0b0c0f;font-family:Inter,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#f2f2ee;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        {{ $isReset ? 'Your password has been reset.' : 'Your account is ready.' }} Sign in with the details inside.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0b0c0f;">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">

                    {{-- Brand --}}
                    <tr>
                        <td style="padding:0 4px 20px 4px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td width="36" height="36" align="center" valign="middle" style="width:36px;height:36px;background-color:#ffffff;border-radius:10px;font-size:18px;font-weight:800;color:#0b0c0f;line-height:36px;">
                                        K<span style="color:#c8f169;">&#8226;</span>
                                    </td>
                                    <td style="padding-left:10px;font-size:18px;font-weight:700;color:#f2f2ee;letter-spacing:-0.2px;">{{ $brand }}</td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background-color:#15171c;border:1px solid #26282e;border-radius:18px;overflow:hidden;">
                            {{-- Banner, like the profile header --}}
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td height="96" style="height:96px;background-color:#2a3550;background-image:linear-gradient(110deg,#8fd3f4 0%,#2a3550 38%,#15171c 62%,#d9a7ef 100%);border-radius:18px 18px 0 0;">&nbsp;</td>
                                </tr>
                            </table>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td style="padding:28px 28px 8px 28px;">
                                        <span style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:#1f2a14;color:#c8f169;font-size:12px;font-weight:600;">
                                            &#9679; {{ $isReset ? 'Password reset' : 'Account ready' }}
                                        </span>
                                        <h1 style="margin:14px 0 6px 0;font-size:24px;line-height:1.25;font-weight:700;color:#f2f2ee;letter-spacing:-0.3px;">
                                            {{ $isReset ? "Here's your new password, {$name}" : "Welcome to {$brand}, {$name}" }}
                                        </h1>
                                        <p style="margin:0;font-size:15px;line-height:1.6;color:#a1a3aa;">
                                            @if ($isReset)
                                                Your password was reset by HR. Use the details below to sign in; any devices you were signed in on have been signed out.
                                            @else
                                                Your employee account has been created. Use the details below to sign in.
                                            @endif
                                        </p>
                                    </td>
                                </tr>

                                {{-- Credentials --}}
                                <tr>
                                    <td style="padding:20px 28px 4px 28px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#0f1013;border:1px solid #26282e;border-radius:14px;">
                                            <tr>
                                                <td style="padding:14px 18px;border-bottom:1px solid #26282e;">
                                                    <div style="font-size:12px;color:#8b8d94;">Login email</div>
                                                    <div style="margin-top:3px;font-size:15px;font-weight:600;color:#f2f2ee;word-break:break-all;">{{ $email }}</div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding:14px 18px;{{ $employeeCode ? 'border-bottom:1px solid #26282e;' : '' }}">
                                                    <div style="font-size:12px;color:#8b8d94;">Password</div>
                                                    <div style="margin-top:3px;font-family:'JetBrains Mono',Consolas,'Courier New',monospace;font-size:20px;font-weight:700;letter-spacing:2px;color:#c8f169;">{{ $password }}</div>
                                                </td>
                                            </tr>
                                            @if ($employeeCode)
                                                <tr>
                                                    <td style="padding:14px 18px;">
                                                        <div style="font-size:12px;color:#8b8d94;">Employee code</div>
                                                        <div style="margin-top:3px;font-size:15px;font-weight:600;color:#f2f2ee;">{{ $employeeCode }}</div>
                                                    </td>
                                                </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>

                                {{-- Button --}}
                                <tr>
                                    <td style="padding:24px 28px 8px 28px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td style="background-color:#c8f169;border-radius:12px;">
                                                    <a href="{{ $loginUrl }}" style="display:inline-block;padding:13px 22px;font-size:15px;font-weight:700;color:#15171c;text-decoration:none;">Sign in to {{ $brand }} &rarr;</a>
                                                </td>
                                            </tr>
                                        </table>
                                        <p style="margin:12px 0 0 0;font-size:12px;color:#8b8d94;">Or open <a href="{{ $loginUrl }}" style="color:#c8f169;text-decoration:none;">{{ $loginUrl }}</a></p>
                                    </td>
                                </tr>

                                {{-- Safety --}}
                                <tr>
                                    <td style="padding:20px 28px 28px 28px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#1b1d22;border-radius:12px;">
                                            <tr>
                                                <td style="padding:12px 14px;font-size:13px;line-height:1.55;color:#a1a3aa;">
                                                    &#128274; Keep this password private: no one from {{ $brand }} will ever ask you for it. The password is case-sensitive. If you weren't expecting this email, contact HR.
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:20px 8px 0 8px;font-size:12px;line-height:1.5;color:#6b6d74;">
                            This is an automated message from {{ $brand }}. Please don't reply.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
