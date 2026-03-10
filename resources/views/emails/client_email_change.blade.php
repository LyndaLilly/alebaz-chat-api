<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Confirm Email Change</title>
</head>
<body style="font-family: Arial, sans-serif; background:#f7f7f7; padding:20px;">
    <div style="max-width:600px; margin:0 auto; background:#ffffff; padding:30px; border-radius:10px;">
        <h2 style="margin-top:0;">Confirm Your New Email</h2>

        <p>Hello,</p>

        <p>You requested to change your Alebaz account email to:</p>

        <p style="font-weight:bold;">{{ $email }}</p>

        <p>Use the verification code below to confirm this change:</p>

        <div style="font-size:32px; font-weight:bold; letter-spacing:6px; margin:20px 0;">
            {{ $code }}
        </div>

        <p>This code expires in {{ $expiresInMinutes }} minutes.</p>

        <p>If you did not request this change, you can ignore this email.</p>

        <p style="margin-top:30px;">Alebaz Team</p>
    </div>
</body>
</html>