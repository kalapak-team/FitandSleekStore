@php
    $purposeText = match ($purpose ?? 'login') {
        'register' => 'continue creating your Fit and Sleek account',
        'forgot' => 'reset your password',
        default => 'sign in to your account',
    };
@endphp
Fit and Sleek — Your verification code

Use this code to {{ $purposeText }}:

{{ $code }}

This code expires in {{ (int) ($expiresMinutes ?? 10) }} minutes.
If you did not request this, you can safely ignore this email.
