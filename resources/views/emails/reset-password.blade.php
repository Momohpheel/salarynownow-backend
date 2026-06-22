<!DOCTYPE html>
<html>
<head>
    <title>Reset Your Password</title>
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

        <h2>Reset Your Password</h2>

        <p>You're receiving this email because we received a password reset request for your account.</p>

        <a href="{{ config('app.frontend_url', 'http://localhost:3000') }}/reset-password?token={{ $token }}&email={{ urlencode($email) }}" class="button">Reset Password</a>

        <p>If you didn't request a password reset, no further action is required.</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
