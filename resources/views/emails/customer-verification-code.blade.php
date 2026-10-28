@extends('emails.layout')
@php
    [$heading, $intro] = match ($type) {
        'password_reset' => ['Reset your password', 'Use this code to reset your password:'],
        'email_change_current' => ['Confirm your email change', 'You asked to change the email on your account. Enter this code to confirm it\'s you:'],
        'email_change_new' => ['Confirm your new email', 'Enter this code to confirm this is your new account email:'],
        default => ['Verify your email', 'Thanks for signing up. Enter this code to verify your email address:'],
    };
@endphp

@section('title', $heading)
@section('preheader', "Your code is {$code}")
@section('heading', $heading)

@section('content')
    <p style="margin:0 0 16px;">Hello,</p>
    <p style="margin:0 0 8px;">{{ $intro }}</p>
    @include('emails.partials.code', ['code' => $code, 'expires' => '10 minutes'])
    @php
        $base = rtrim((string) config('app.frontend_url'), '/');
        // Only sign-up has a page that takes the code from a link; the other codes are typed into an open form.
        $verifyUrl = $base && $type === 'email_verification' && !empty($email)
            ? $base.'/verify-email?'.http_build_query(['email' => $email, 'code' => $code]) : null;
    @endphp
    @if ($verifyUrl)
        @include('emails.partials.button', ['url' => $verifyUrl, 'label' => 'Verify email'])
    @endif
    <p style="margin:0;font-size:13px;line-height:20px;color:#71717a;">If you didn't request this code, you can ignore this email.</p>
@endsection
