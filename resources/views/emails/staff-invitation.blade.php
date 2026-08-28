<!DOCTYPE html>
<html>
<head>
    <title>You're invited to Sugar Payroll</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            color: #333;
            line-height: 1.6;
        }
        .container {
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            margin-bottom: 30px;
        }
        .logo{display:block}.logo svg{display:block}
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #0B6F49;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            margin: 20px 0;
        }
        .footer {
            margin-top: 40px;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 260 44" width="220" height="38" aria-label="Sugar Payroll">
  <g transform="translate(4 10)">
    <rect x="0" y="0" width="10" height="10" fill="#033222"/><rect x="12" y="0" width="10" height="10" fill="#A6CCAB"/><rect x="24" y="0" width="10" height="10" fill="#033222"/>
    <rect x="0" y="12" width="10" height="10" fill="#A6CCAB"/><rect x="12" y="12" width="10" height="10" fill="#033222"/><rect x="24" y="12" width="10" height="10" fill="#A6CCAB"/>
    <rect x="0" y="24" width="10" height="10" fill="#033222"/><rect x="12" y="24" width="10" height="10" fill="#A6CCAB"/><rect x="24" y="24" width="10" height="10" fill="#033222"/>
  </g>
  <text x="46" y="29" font-family="Arial, sans-serif" font-size="20" font-weight="700" fill="#17241C">Sugar</text>
  <text x="110" y="29" font-family="Arial, sans-serif" font-size="20" font-weight="700" fill="#0B6F49">Payroll</text>
</svg>
</div>
<hr style="border-color:#D9E6C4;">
        </div>

        <h2>You're invited to Sugar Payroll</h2>

        <p>Hi {{ $staff->first_name }},</p>

        <p>{{ $employer->company_name }} has added you to Sugar Payroll. Set up your password to view your payslips, request salary advances, and access staff-exclusive deals.</p>

        <a href="{{ $inviteLink }}" class="button">Set up my account</a>

        <p>Or paste this link into your browser:</p>
        <p><a href="{{ $inviteLink }}">{{ $inviteLink }}</a></p>

        <p>This link expires in 24 hours.</p>

        <div class="footer">
            <p>Sugar Payroll — Payroll, Sweetened.</p>
        </div>
    </div>
</body>
</html>
