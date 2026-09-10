<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject ?? 'Dream More Marketplace' }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f4f5f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .wrapper { width: 100%; background-color: #f4f5f7; padding: 40px 0; }
        .container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06); }
        .header { background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%); padding: 32px 40px; text-align: center; }
        .header h1 { color: #ffffff; font-size: 20px; font-weight: 700; margin: 0; letter-spacing: -0.02em; }
        .header p { color: rgba(255,255,255,0.85); font-size: 13px; margin: 4px 0 0; }
        .body { padding: 32px 40px; color: #1f2937; line-height: 1.6; }
        .body h2 { font-size: 18px; font-weight: 700; margin: 0 0 16px; color: #111827; }
        .body p { font-size: 14px; margin: 0 0 16px; color: #4b5563; }
        .info-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 20px; margin: 24px 0; }
        .info-box .label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.05em; color: #2563eb; font-weight: 600; }
        .info-box .title { font-size: 16px; font-weight: 700; color: #1d4ed8; margin: 4px 0; }
        .info-box .desc { font-size: 13px; color: #4b5563; }
        .detail-table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        .detail-table td { padding: 10px 0; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .detail-table td:first-child { color: #6b7280; width: 40%; }
        .detail-table td:last-child { color: #111827; font-weight: 500; text-align: right; }
        .btn { display: inline-block; background: #6366f1; color: #ffffff !important; text-decoration: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; font-weight: 600; margin: 8px 0; }
        .btn:hover { background: #4f46e5; }
        .btn.danger { background: #dc2626; }
        .footer { padding: 24px 40px; text-align: center; border-top: 1px solid #f3f4f6; }
        .footer p { font-size: 12px; color: #9ca3af; margin: 0 0 4px; }
        .footer a { color: #6366f1; text-decoration: none; }
        .note { background: #f9fafb; border-left: 3px solid #6366f1; padding: 12px 16px; font-size: 13px; color: #4b5563; margin: 16px 0; border-radius: 0 6px 6px 0; }
        .reason-box { background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 16px; font-size: 13px; color: #7f1d1d; margin: 16px 0; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="container">
            <div class="header">
                <h1>Dream More Marketplace</h1>
                <p>{{ $headerSubtitle ?? 'Notification' }}</p>
            </div>
            <div class="body">
                @yield('content')
            </div>
            <div class="footer">
                <p>Dream More AppWorks — Freelance Marketplace</p>
                <p>If you have questions, contact us at <a href="mailto:support@dreammore.app">support@dreammore.app</a></p>
                <p style="margin-top: 12px; font-size: 11px; color: #d1d5db;">This is an automated email. Please do not reply directly.</p>
            </div>
        </div>
    </div>
</body>
</html>
