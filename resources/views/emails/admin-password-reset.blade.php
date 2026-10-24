@extends('emails.layout')
@php
    $base = rtrim((string) config('app.frontend_url'), '/');
    // No password is shared: the button opens the site's password page with a
    // one-time code, where they choose a new one themselves.
    $url = $base
        ? $base.'/forgot-password?'.http_build_query(['actor' => 'admin', 'email' => $admin->email, 'code' => $code])
        : null;
@endphp

@section('title', 'Admin password reset')
@section('preheader', 'A super admin reset your password. Create a new one to sign in.')
@section('heading', 'Your password was reset')

@section('content')
    <p style="margin:0 0 16px;">Hello {{ $admin->name }},</p>
    <p style="margin:0 0 8px;">A super admin reset the password for your admin account ({{ $admin->email }}). Create a new password to sign in again:</p>
    @if ($url)
        @include('emails.partials.button', ['url' => $url, 'label' => 'Create new password'])
    @endif
    <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">This link works for one day. If you didn't expect this, contact your super admin.</p>
@endsection
