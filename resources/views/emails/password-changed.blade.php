@extends('emails.layout')

@section('title', 'Password updated')
@section('preheader', 'The password for your account has been changed.')
@section('heading', 'Password updated')

@section('content')
    <p style="margin:0 0 16px;">Hello{{ $name ? ' '.$name : '' }},</p>
    <p style="margin:0 0 16px;">Your password was just changed, and you've been signed out on all devices.</p>

    {{-- Labels take the narrowest column (width:1% + nowrap) so the time and IP fit on one line. --}}
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;font-size:14px;line-height:20px;">
        <tr>
            <td width="1%" style="width:1%;white-space:nowrap;padding:14px 0 4px 20px;color:#71717a;">Time</td>
            <td align="right" style="padding:14px 20px 4px 16px;color:#09090b;font-weight:600;">{{ $changedAt }}</td>
        </tr>
        <tr>
            <td width="1%" style="width:1%;white-space:nowrap;padding:4px 0 14px 20px;color:#71717a;">From IP</td>
            <td align="right" style="padding:4px 20px 14px 16px;color:#09090b;font-weight:600;">{{ $ip ?: 'Unknown' }}</td>
        </tr>
    </table>

    <p style="margin:0 0 16px;">If this was you, no action is needed.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;">
        <tr>
            <td style="padding:14px 20px;font-size:14px;line-height:21px;color:#7f1d1d;">
                <strong>Wasn't you?</strong> Reset your password now and contact support.
            </td>
        </tr>
    </table>
    @if ($recoverUrl)
        <div style="height:12px;line-height:12px;font-size:0;">&nbsp;</div>
        @include('emails.partials.button', ['url' => $recoverUrl, 'label' => 'Reset password'])
    @endif
@endsection
