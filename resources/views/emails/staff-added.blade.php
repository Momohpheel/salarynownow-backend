<!DOCTYPE html>
<html>
<head>
    <title>Welcome to SalaryNowNow - Staff Account</title>
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

        <h2>Welcome to SalaryNowNow!</h2>

        <p>Hi {{ $staff->first_name ?? $staff->name }},</p>

        <p>{{ $employer->company_name ?? $employer->name }} has added you as a staff member on SalaryNowNow.</p>

        <p>Here are your login details:</p>

        <ul>
            <li><strong>Email:</strong> {{ $staff->email }}</li>
            <li><strong>Password:</strong> {{ $password }}</li>
        </ul>

        <a href="{{ $loginUrl }}" class="button">Login to your account</a>

        <p>Or paste this link into your browser:</p>
        <p><a href="{{ $loginUrl }}">{{ $loginUrl }}</a></p>

        <p>Please log in and change your password immediately.</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
