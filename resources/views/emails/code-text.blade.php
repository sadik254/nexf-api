{{-- Plain-text part for code emails; Gmail reads this to offer its "Copy code" chip. --}}
@php $expires = (int) ($expiresMinutes ?? 10); @endphp
Your NEXF code is {{ $code }}

It expires in {{ $expires >= 1440 ? ($expires / 1440).' days' : $expires.' minutes' }}. If you didn't request this code, you can ignore this email.
