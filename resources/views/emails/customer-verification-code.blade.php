@php
    $label = $type === 'password_reset' ? 'password reset' : 'email verification';
@endphp

Hello,

Your {{ $label }} code is: {{ $code }}

This code will expire soon. If you did not request this, you can ignore this email.
