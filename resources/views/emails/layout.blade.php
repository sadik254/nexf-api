@php
    try {
        $site = \App\Models\SiteSetting::find('footer')?->payload ?? [];
    } catch (\Throwable $e) {
        $site = [];
    }
    $brand = trim($site['name'] ?? '') ?: 'NEXF Lifestyle';
    $supportEmail = trim($site['email'] ?? '');
    $helpline = trim($site['helpline'] ?? '');
    $address = trim($site['address'] ?? '');
    // Same as the storefront footer: a fixed "© <year>" then the Site info text, minus any legacy "© 2025" lead-in.
    $copyrightText = trim(preg_replace('/^\s*(©|\(c\))?\s*(\{year\}|\d{4})?\s*/iu', '', (string) ($site['copyright'] ?? '')));
    $copyright = '© '.date('Y').' '.($copyrightText ?: 'NEXF Lifestyle Ltd.');
    $siteUrl = rtrim((string) config('app.frontend_url'), '/');
    // Embedded (cid:) so the logo shows without hosting and in clients that block remote images.
    // $message is only set when the mail is actually sent; previews fall back to a data URI.
    $logoPath = resource_path('email/logo.png');
    $logoSrc = isset($message) ? $message->embed($logoPath) : 'data:image/png;base64,'.base64_encode(file_get_contents($logoPath));
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>@yield('title', $brand)</title>
<style>
    body { margin: 0; padding: 0; width: 100% !important; background: #f3f4f6; }
    a { color: #1d4ed8; }
    @media only screen and (max-width: 600px) {
        .container { width: 100% !important; }
        .px { padding-left: 20px !important; padding-right: 20px !important; }
        .code { font-size: 28px !important; letter-spacing: 6px !important; }
    }
</style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#111827;-webkit-text-size-adjust:100%;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">@yield('preheader')&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;&#8199;&#847;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f3f4f6;">
<tr>
<td align="center" style="padding:32px 12px;">
    <table role="presentation" class="container" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:560px;">
        <tr>
            <td class="px" style="padding:0 4px 16px;">
                @php $logo = '<img src="'.e($logoSrc).'" width="124" height="35" alt="'.e($brand).'" style="display:block;width:124px;height:35px;border:0;outline:none;text-decoration:none;font-size:22px;font-weight:700;color:#056fff;">'; @endphp
                @if ($siteUrl)
                    <a href="{{ $siteUrl }}" style="display:inline-block;text-decoration:none;">{!! $logo !!}</a>
                @else
                    {!! $logo !!}
                @endif
            </td>
        </tr>
        <tr>
            <td style="background:#ffffff;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                    <tr><td style="height:4px;background:#056fff;font-size:0;line-height:0;">&nbsp;</td></tr>
                    <tr>
                        <td class="px" style="padding:32px 36px 36px;font-size:15px;line-height:24px;color:#374151;">
                            @hasSection('heading')
                                <h1 style="margin:0 0 16px;font-size:22px;line-height:30px;font-weight:700;color:#111827;">@yield('heading')</h1>
                            @endif
                            @yield('content')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
        <tr>
            <td class="px" align="center" style="padding:24px 16px 0;font-size:12px;line-height:19px;color:#6b7280;">
                @if ($supportEmail || $helpline)
                    <p style="margin:0 0 6px;">
                        Need help?
                        @if ($supportEmail)<a href="mailto:{{ $supportEmail }}" style="color:#4b5563;">{{ $supportEmail }}</a>@endif
                        @if ($supportEmail && $helpline) &middot; @endif
                        @if ($helpline)<a href="tel:{{ preg_replace('/[^\d+]/', '', $helpline) }}" style="color:#4b5563;">{{ $helpline }}</a>@endif
                    </p>
                @endif
                @if ($address)
                    <p style="margin:0 0 6px;">{{ $address }}</p>
                @endif
                <p style="margin:0;">{{ $copyright }}</p>
            </td>
        </tr>
    </table>
</td>
</tr>
</table>
</body>
</html>
