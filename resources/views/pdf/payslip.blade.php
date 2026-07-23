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
            font-size: 28px;
            font-weight: bold;
            color: #4CAF50;
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
        <div class="logo">SalaryNowNow</div>
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
        <p>SalaryNowNow — Payroll & Embedded Finance Platform</p>
    </div>
</body>
</html>
