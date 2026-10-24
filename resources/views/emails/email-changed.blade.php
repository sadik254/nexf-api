@extends('emails.layout')
@php $isOld = $recipient === 'old'; @endphp

@section('title', $isOld ? 'Your account email was changed' : 'Your account email is updated')
@section('preheader', $isOld ? "Your sign-in email was changed to {$newEmail}." : 'You will now sign in with this email address.')
@section('heading', $isOld ? 'Your account email was changed' : 'Your email is updated')

@section('content')
    <p style="margin:0 0 16px;">Hello{{ $name ? ' '.$name : '' }},</p>
    <p style="margin:0 0 16px;">
        @if ($isOld)
            The sign-in email for your {{ $accountType }} account was changed. We'll no longer send account emails to this address.
        @else
            This is now the sign-in email for your {{ $accountType }} account. Order updates and account emails will come here.
        @endif
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;line-height:20px;">
        <tr>
            <td style="padding:14px 20px 4px;color:#6b7280;white-space:nowrap;">Old email</td>
            <td align="right" style="padding:14px 20px 4px;color:#6b7280;text-decoration:line-through;word-break:break-all;">{{ $oldEmail }}</td>
        </tr>
        <tr>
            <td style="padding:4px 20px;color:#6b7280;white-space:nowrap;">New email</td>
            <td align="right" style="padding:4px 20px;color:#111827;font-weight:600;word-break:break-all;">{{ $newEmail }}</td>
        </tr>
        <tr>
            <td style="padding:4px 20px 14px;color:#6b7280;white-space:nowrap;">When</td>
            <td align="right" style="padding:4px 20px 14px;color:#111827;">{{ $changedAt }}</td>
        </tr>
    </table>

    @if ($isOld)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;background:#fef2f2;border:1px solid #fecaca;border-radius:10px;">
            <tr>
                <td style="padding:14px 20px;font-size:14px;line-height:21px;color:#7f1d1d;">
                    <strong>Didn't make this change?</strong> Contact our support team right away so we can secure your account.
                </td>
            </tr>
        </table>
        @if ($supportUrl)
            <div style="height:12px;line-height:12px;font-size:0;">&nbsp;</div>
            @include('emails.partials.button', ['url' => $supportUrl, 'label' => 'Contact support'])
        @endif
    @elseif ($signInUrl)
        @include('emails.partials.button', ['url' => $signInUrl, 'label' => 'Sign in'])
    @endif
@endsection
