<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Password reset code</title>
  </head>
  <body style="margin:0;padding:0;background:#f6f7fb;font-family:Arial,Helvetica,sans-serif;">
    <div style="max-width:560px;margin:0 auto;padding:24px 16px;">
      <div style="background:#ffffff;border-radius:12px;border:1px solid #e7e9f1;padding:20px 18px;">
        <h2 style="margin:0 0 10px;font-size:18px;color:#111827;">Password reset</h2>
        <p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:#374151;">
          Use the code below to reset your password. This code expires in {{ $expiresMinutes }} minutes and can only be used once.
        </p>
        <div style="padding:14px 12px;border-radius:10px;background:#0b1220;color:#ffffff;text-align:center;">
          <div style="font-size:12px;letter-spacing:0.18em;text-transform:uppercase;color:#9ca3af;margin-bottom:6px;">
            One-time code
          </div>
          <div style="font-size:28px;font-weight:800;letter-spacing:0.22em;">
            {{ $otp }}
          </div>
        </div>
        <p style="margin:14px 0 0;font-size:12px;line-height:1.5;color:#6b7280;">
          If you didn’t request this, you can ignore this email.
        </p>
      </div>
      <p style="margin:12px 0 0;font-size:11px;color:#9ca3af;text-align:center;">
        © {{ date('Y') }} SmartDTR
      </p>
    </div>
  </body>
</html>

