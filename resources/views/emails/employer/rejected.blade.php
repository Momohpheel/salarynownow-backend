@component('mail::message')
# KYC Submission Rejected

Hello **{{ $employee->name }}**,

We regret to inform you that your KYC submission for **{{ $employee->company_name }}** has been rejected.

**Reason for rejection:**

*{{ $reason }}*

Please log in to your dashboard to review the details and resubmit the required documents.

Thanks,<br>
{{ config('app.name') }}
@endcomponent
