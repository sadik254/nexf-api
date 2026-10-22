Hello {{ $admin->name }},

Your admin account has been created. Use the secure link below to create your password:

{{ url('/forgot-password?actor=admin&email='.urlencode($admin->email).'&code='.urlencode($code).'&invite=1') }}
