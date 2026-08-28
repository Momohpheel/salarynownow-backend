<!DOCTYPE html>
<html>
<head>
    <title>Payslip - {{ $payslip->period }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            padding: 20px;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo {
            display: inline-block;
            text-align: center;
        }
        .logo svg {
            display: block;
            margin: 0 auto;
        }
        .company-info {
            margin-top: 10px;
            font-size: 14px;
        }
        .employee-info {
            margin-bottom: 30px;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        .table th, .table td {
            border: 1px solid #ddd;
            padding: 12px;
            text-align: left;
        }
        .table th {
            background-color: #f2f2f2;
        }
        .total {
            font-weight: bold;
            font-size: 16px;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            color: #666;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="logo">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 280 48" width="280" height="48" aria-label="Sugar Payroll">
                <g transform="translate(4 8)">
                    <rect x="0" y="0" width="12" height="12" fill="#033222"/>
                    <rect x="14" y="0" width="12" height="12" fill="#A6CCAB"/>
                    <rect x="28" y="0" width="12" height="12" fill="#033222"/>
                    <rect x="0" y="14" width="12" height="12" fill="#A6CCAB"/>
                    <rect x="14" y="14" width="12" height="12" fill="#033222"/>
                    <rect x="28" y="14" width="12" height="12" fill="#A6CCAB"/>
                    <rect x="0" y="28" width="12" height="12" fill="#033222"/>
                    <rect x="14" y="28" width="12" height="12" fill="#A6CCAB"/>
                    <rect x="28" y="28" width="12" height="12" fill="#033222"/>
                </g>
                <text x="54" y="32" font-family="Arial, sans-serif" font-size="22" font-weight="700" fill="#17241C">Sugar</text>
                <text x="124" y="32" font-family="Arial, sans-serif" font-size="22" font-weight="700" fill="#0B6F49">Payroll</text>
            </svg>
        </div>
        <div class="company-info">{{ $companyName ?? 'Company' }}</div>
    </div>

    <div class="employee-info">
        <h3>Employee Details</h3>
        <p><strong>Name:</strong> {{ $payslip->user->name }}</p>
        <p><strong>Period:</strong> {{ $payslip->period }}</p>
        <p><strong>Department:</strong> {{ $payslip->user->department ?? 'N/A' }}</p>
        <p><strong>Job Title:</strong> {{ $payslip->user->job_title ?? 'N/A' }}</p>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>Description</th>
                <th>Amount (₦)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Gross Salary</td>
                <td>{{ number_format($payslip->gross_salary, 2) }}</td>
            </tr>
            @if($payslip->bonus_amount > 0)
            <tr>
                <td>Bonus ({{ $payslip->bonus_type }})</td>
                <td>{{ number_format($payslip->bonus_amount, 2) }}</td>
            </tr>
            @endif
            <tr>
                <td colspan="2"><strong>Deductions:</strong></td>
            </tr>
            <tr>
                <td>Pension (Employee)</td>
                <td>-{{ number_format($payslip->pension_employee, 2) }}</td>
            </tr>
            <tr>
                <td>Pension (Employer)</td>
                <td>-{{ number_format($payslip->pension_employer, 2) }}</td>
            </tr>
            <tr>
                <td>Tax (PAYE)</td>
                <td>-{{ number_format($payslip->tax_deduction, 2) }}</td>
            </tr>
            <tr>
                <td>NHF</td>
                <td>-{{ number_format($payslip->nhf, 2) }}</td>
            </tr>
            <tr>
                <td>Other Deductions ({{ $payslip->deduction_type }})</td>
                <td>-{{ number_format($payslip->other_deductions, 2) }}</td>
            </tr>
            <tr class="total">
                <td>Net Pay</td>
                <td>{{ number_format($payslip->net_salary, 2) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="footer">
        <p>Sugar Payroll — Payroll, Sweetened.</p>
    </div>
</body>
</html>
