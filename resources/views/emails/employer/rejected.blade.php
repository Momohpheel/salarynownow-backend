<!DOCTYPE html>
<html>
<head>
    <title>KYC Submission Rejected</title>
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

        <h2>KYC Submission Rejected</h2>

        <p>Hello <strong>{{ $employee->name }}</strong>,</p>

        <p>We regret to inform you that your KYC submission for <strong>{{ $employee->company_name }}</strong> has been rejected.</p>

        <p><strong>Reason for rejection:</strong></p>
        <p><em>{{ $reason }}</em></p>

        <p>Please log in to your dashboard to review the details and resubmit the required documents.</p>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
