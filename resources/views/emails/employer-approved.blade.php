<!DOCTYPE html>
<html>
<head>
    <title>Your SalaryNowNow Account Has Been Approved!</title>
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

        <h2>Congratulations! Your Account Has Been Approved!</h2>

        <p>Hi {{ $employer->name }},</p>

        <p>Great news! Your SalaryNowNow account has been approved. You now have full access to all our features including payroll processing, wallet top-ups, staff management, and more!</p>

        <p>Login to your account to get started!</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
