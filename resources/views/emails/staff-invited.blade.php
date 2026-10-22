Hello {{ $name }},

You've been added to {{ $storeName }}. Create your password to access the store console:

{{ url('/forgot-password?actor=seller&email='.urlencode($email).'&code='.urlencode($code).'&invite=1') }}
