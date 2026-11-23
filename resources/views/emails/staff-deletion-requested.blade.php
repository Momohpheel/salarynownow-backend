<!DOCTYPE html>
<html>
<head>
    <title>Staff Deletion Awaiting Your Approval</title>
    <style>
        body { font-family: Arial, sans-serif; color: #333; line-height: 1.6; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { margin-bottom: 30px; }
        .logo{display:block}.logo svg{display:block}
        .footer { margin-top: 40px; color: #666; font-size: 12px; }
        .button {
            display: inline-block; margin-top: 20px; padding: 12px 24px;
            background-color: #0B6F49; color: #ffffff; text-decoration: none;
            border-radius: 6px; font-weight: bold;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 260 44" width="220" height="38" aria-label="Sugar Payroll">
  <g transform="translate(4 10)">
    <rect x="0" y="0" width="10" height="10" fill="#033222"/><rect x="12" y="0" width="10" height="10" fill="#A6CCAB"/><rect x="24" y="0" width="10" height="10" fill="#033222"/>
    <rect x="0" y="12" width="10" height="10" fill="#A6CCAB"/><rect x="12" y="12" width="10" height="10" fill="#033222"/><rect x="24" y="12" width="10" height="10" fill="#A6CCAB"/>
    <rect x="0" y="24" width="10" height="10" fill="#033222"/><rect x="12" y="24" width="10" height="10" fill="#A6CCAB"/><rect x="24" y="24" width="10" height="10" fill="#033222"/>
  </g>
  <text x="46" y="29" font-family="Arial, sans-serif" font-size="20" font-weight="700" fill="#17241C">Sugar</text>
  <text x="110" y="29" font-family="Arial, sans-serif" font-size="20" font-weight="700" fill="#0B6F49">Payroll</text>
</svg>
</div>
<hr style="border-color:#D9E6C4;">
        </div>

        <h2>Staff Deletion Awaiting Your Approval</h2>

        <p>Hi {{ $approverName ?? 'there' }},</p>

        <p>
            {{ $requester->name ?? 'A team member' }} has requested to remove a staff member from
            your staff list. This needs your approval before it takes effect.
        </p>

        <ul>
            <li><strong>Staff member:</strong> {{ $staff->name ?? ($staff->first_name . ' ' . $staff->last_name) }}</li>
            @if (!empty($staff->email))
                <li><strong>Email:</strong> {{ $staff->email }}</li>
            @endif
            @if (!empty($staff->job_title))
                <li><strong>Job title:</strong> {{ $staff->job_title }}</li>
            @endif
            @if (!empty($staff->department))
                <li><strong>Department:</strong> {{ $staff->department }}</li>
            @endif
            @if (!empty($reason))
                <li><strong>Reason:</strong> {{ $reason }}</li>
            @endif
        </ul>

        <p>Please review and approve or reject this request.</p>

        <a href="{{ config('app.frontend_url', 'https://sugarpayroll.com') }}/staff" class="button">Review Request</a>

        <div class="footer">
            <p>Sugar Payroll — Payroll, Sweetened.</p>
        </div>
    </div>
</body>
</html>
