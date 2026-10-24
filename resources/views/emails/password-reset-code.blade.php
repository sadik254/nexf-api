@extends('emails.layout')
@php
    $base = rtrim((string) config('app.frontend_url'), '/');
    // Opens the reset page on the code step with this code filled in (still checked by the server),
    // instead of a fresh request that would replace the code in this email.
    $url = $base && !empty($email)
        ? $base.'/forgot-password?'.http_build_query(['actor' => $accountType, 'email' => $email, 'code' => $code]) : null;
    $heading = $invite ? "You're invited to be a {$accountType}" : 'Reset your password';
@endphp

@section('title', $heading)
@section('preheader', $invite ? "Your setup code is {$code}" : "Your password reset code is {$code}")
@section('heading', $heading)

@section('content')
    <p style="margin:0 0 16px;">Hello{{ !empty($name) ? ' '.$name : '' }},</p>
    @if ($invite)
        <p style="margin:0 0 8px;">You've been added as a {{ $accountType }}. Tap the button below to create your password. If it asks, this is your code:</p>
    @else
        <p style="margin:0 0 8px;">Use this code to reset your {{ $accountType }} account password:</p>
    @endif
    @include('emails.partials.code', ['code' => $code, 'expires' => $expiresMinutes.' minutes'])
    @if ($url)
        @include('emails.partials.button', ['url' => $url, 'label' => $invite ? 'Set password' : 'Reset password'])
    @endif
    <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">
        @if ($invite)
            The code expires in {{ $expiresMinutes }} minutes. If it runs out, tap Resend on that page and we'll email a new one.
        @else
            If you didn't request a password reset, you can ignore this email. Your password won't change.
        @endif
    </p>
@endsection
