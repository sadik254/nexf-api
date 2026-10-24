@extends('emails.layout')

@section('title', 'Your password was changed')
@section('preheader', 'The password for your account was just changed.')
@section('heading', 'Your password was changed')

@section('content')
    <p style="margin:0 0 16px;">Hello{{ $name ? ' '.$name : '' }},</p>
    <p style="margin:0 0 16px;">
        The password for your {{ $accountType }} account was
        {{ $via === 'reset' ? 'reset with a verification code' : 'changed from your account settings' }}.
        For your security, you've been signed out on all devices.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;line-height:20px;">
        <tr>
            <td style="padding:14px 20px 4px;color:#6b7280;">When</td>
            <td align="right" style="padding:14px 20px 4px;color:#111827;font-weight:600;">{{ $changedAt }}</td>
        </tr>
        <tr>
            <td style="padding:4px 20px 14px;color:#6b7280;">IP address</td>
            <td align="right" style="padding:4px 20px 14px;color:#111827;font-weight:600;">{{ $ip ?: 'Unknown' }}</td>
        </tr>
    </table>

    <p style="margin:0 0 16px;">If this was you, there's nothing else to do.</p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;">
        <tr>
            <td style="padding:14px 20px;font-size:14px;line-height:21px;color:#7f1d1d;">
                <strong>Didn't change your password?</strong> Someone else may have access to your account.
                Reset your password right away and contact our support team.
            </td>
        </tr>
    </table>
    @if ($recoverUrl)
        <div style="height:12px;line-height:12px;font-size:0;">&nbsp;</div>
        @include('emails.partials.button', ['url' => $recoverUrl, 'label' => 'Reset password'])
    @endif
@endsection
