Hello {{ $admin->name }},

A super admin requested a password reset for your admin account. Use the secure link below to create a new password:

{{ url('/forgot-password?actor=admin&email='.urlencode($admin->email).'&code='.urlencode($code)) }}
