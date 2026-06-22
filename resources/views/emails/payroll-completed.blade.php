<!DOCTYPE html>
<html>
<head>
    <title>Payroll Processed Successfully</title>
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

        <h2>Payroll Processed Successfully!</h2>

        <p>Hi {{ $payroll->user->name ?? 'there' }},</p>

        <p>Your payroll has been processed successfully!</p>

        <ul>
            <li><strong>Description:</strong> {{ $payroll->description }}</li>
            <li><strong>Total Amount:</strong> ₦{{ number_format($payroll->amount, 2) }}</li>
            <li><strong>Staff Count:</strong> {{ $payroll->staff_count }}</li>
            <li><strong>Status:</strong> {{ $payroll->status }}</li>
        </ul>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
