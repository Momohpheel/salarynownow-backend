<!DOCTYPE html>
<html>
<head>
    <title>Wallet Topup Successful</title>
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

        <h2>Wallet Topup Successful!</h2>

        <p>Hi {{ $user->name ?? 'there' }},</p>

        <p>Your wallet has been credited successfully!</p>

        <ul>
            <li><strong>Amount:</strong> ₦{{ number_format($walletLog->amount, 2) }}</li>
            <li><strong>Balance Before:</strong> ₦{{ number_format($walletLog->balance_before, 2) }}</li>
            <li><strong>Balance After:</strong> ₦{{ number_format($walletLog->balance_after, 2) }}</li>
            <li><strong>Description:</strong> {{ $walletLog->description }}</li>
        </ul>

        <div class="footer">
            <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
        </div>
    </div>
</body>
</html>
