{{ $isReset ? "Here's your new password, {$name}" : "Welcome to {$brand}, {$name}" }}

{{ $isReset ? 'Your password was reset by HR. Any devices you were signed in on have been signed out.' : 'Your employee account has been created.' }}

Login email: {{ $email }}
Password:    {{ $password }}
@if ($employeeCode)
Employee code: {{ $employeeCode }}
@endif

Sign in: {{ $loginUrl }}

Keep this password private: no one from {{ $brand }} will ever ask you for it. It is case-sensitive.
If you weren't expecting this email, contact HR.
