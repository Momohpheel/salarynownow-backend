<!DOCTYPE html>
<html>
<head>
    <title>You're invited to SalaryNowNow</title>
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
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #4CAF50;
        }
        .button {
            display: inline-block;
            padding: 12px 24px;
            background-color: #4CAF50;
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
            <div class="logo">SalaryNowNow</div>
            <hr>
        </div>

        <h2>You're invited to SalaryNowNow</h2>

        <p>Hi {{ $staff->first_name }},</p>

        <p>{{ $employer->company_name }} has added you to SalaryNowNow. Set up your password to view your payslips, request salary advances, and access staff-exclusive deals.</p>

        <a href="{{ $inviteLink }}" class="button">Set up my account</a>

        <p>Or paste this link into your browser:</p>
        <p><a href="{{ $inviteLink }}">{{ $inviteLink }}</a></p>

        <p>This link expires in 24 hours.</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
