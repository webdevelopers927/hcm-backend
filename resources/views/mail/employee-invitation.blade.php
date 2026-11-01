<x-mail::message>
# Welcome, {{ $name }}

An account has been created for you in {{ config('app.name') }}. Set your password to sign in.

<x-mail::button :url="$url">
Set your password
</x-mail::button>

This link expires in {{ config('auth.passwords.invites.expire') / 60 }} hours.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
