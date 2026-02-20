<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Email Verification</title>
  </head>
  <body style="margin:0;padding:0;background:#EDE6F5;font-family:Arial,Helvetica,sans-serif;color:#212121;">
    <!-- Outer wrapper -->
    <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#EDE6F5;padding:24px 12px;">
      <tr>
        <td align="center">
          <!-- Card -->
          <table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
            <!-- Header -->
            <tr>
              <td style="background:#6a1b9a;padding:22px 24px;">
                <div style="font-size:18px;font-weight:700;color:#ffffff;letter-spacing:0.3px;">
                  {{ config('app.name') }}
                </div>
                <div style="font-size:13px;color:#ffffff;opacity:0.9;margin-top:4px;">
                  Email Verification Code
                </div>
              </td>
            </tr>

            <!-- Body -->
            <tr>
              <td style="padding:24px;">
                <h2 style="margin:0 0 10px 0;font-size:20px;line-height:1.3;color:#212121;">
                  Verify your email
                </h2>

                <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;color:rgb(96,96,96);">
                  Hello, <br />
                  Use the code below to confirm your email address:
                </p>

                <!-- Code box -->
                <div style="background:#F6F0FB;border:1px solid #E3D3F2;border-radius:14px;padding:18px;text-align:center;margin:18px 0;">
                  <div style="font-size:12px;color:rgb(96,96,96);margin-bottom:8px;">
                    Your verification code
                  </div>
                  <div style="font-size:34px;letter-spacing:6px;font-weight:800;color:#6a1b9a;">
                    {{ $code }}
                  </div>
                </div>

                <!-- Expiration -->
                <p style="margin:0 0 14px 0;font-size:14px;line-height:1.6;color:#212121;">
                  This code will expire in
                  <span style="font-weight:700;color:#FF9900;">{{ $expiresInMinutes }} minutes</span>.
                </p>

                <p style="margin:0;font-size:13px;line-height:1.6;color:rgb(96,96,96);">
                  If you didn’t request this, you can safely ignore this email.
                </p>

                <!-- Divider -->
                <div style="height:1px;background:#EEE;margin:22px 0;"></div>

                <!-- Footer -->
                <p style="margin:0;font-size:12px;line-height:1.6;color:rgb(96,96,96);">
                  Need help? Reply to this email or contact support.
                </p>
              </td>
            </tr>

            <!-- Footer bar -->
            <tr>
              <td style="background:#F9F7FB;padding:14px 24px;font-size:12px;color:rgb(96,96,96);text-align:center;">
                © {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
              </td>
            </tr>
          </table>

          <!-- Small note -->
          <div style="max-width:600px;margin-top:10px;font-size:11px;color:rgb(96,96,96);text-align:center;">
            Please don’t share your verification code with anyone.
          </div>
        </td>
      </tr>
    </table>
  </body>
</html>
