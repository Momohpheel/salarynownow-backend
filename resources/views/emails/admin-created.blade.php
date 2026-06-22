<!DOCTYPE html>
<html>
<head>
    <title>Welcome to SalaryNowNow - Admin Account Created</title>
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

        <h2>Welcome to SalaryNowNow!</h2>

        <p>Hi {{ $admin->contact_person }},</p>

        <p>Your admin account has been created successfully! Here are your login details:</p>

        <ul>
            <li><strong>Email:</strong> {{ $admin->email }}</li>
            <li><strong>Password:</strong> {{ $password }}</li>
        </ul>

        <p>Please log in and change your password immediately.</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
