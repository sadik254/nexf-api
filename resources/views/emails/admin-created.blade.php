@extends('emails.layout')
@php
    $base = rtrim((string) config('app.frontend_url'), '/');
    // No password is shared: the button opens the site's password page with a
    // one-time invite code, where they create their own (`invite=1` = "Create your password").
    $url = $base
        ? $base.'/forgot-password?'.http_build_query(['actor' => 'admin', 'email' => $admin->email, 'code' => $code, 'invite' => 1])
        : null;
@endphp

@section('title', 'Your admin account')
@section('preheader', 'Your NEXF admin account is ready. Create your password to sign in.')
@section('heading', 'Your admin account is ready')

@section('content')
    <p style="margin:0 0 16px;">Hello {{ $admin->name }},</p>
    <p style="margin:0 0 16px;">An admin account has been created for you on NEXF with this email: <strong>{{ $admin->email }}</strong>.</p>
    <p style="margin:0 0 8px;">Create your password to get started:</p>
    @if ($url)
        @include('emails.partials.button', ['url' => $url, 'label' => 'Create password'])
    @endif
    <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">This link works for one day. After that, use Forgot password on the admin sign-in page with this email. If you weren't expecting this, you can ignore this email.</p>
@endsection
