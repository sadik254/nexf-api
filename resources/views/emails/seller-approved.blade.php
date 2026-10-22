Hello {{ $seller->seller_name }},

Your seller account has been approved.

@if ($code)
Use the secure link below to create your password:

{{ url('/forgot-password?actor=seller&email='.urlencode($seller->email).'&code='.urlencode($code).'&invite=1') }}
@else
Your existing password is unchanged. Sign in to your seller account:

{{ url('/seller') }}
@endif
