@extends('emails.layout')
@php
    $base = rtrim((string) config('app.frontend_url'), '/');
    // No password is shared. A new seller gets a Create password button (a one-time
    // invite code to the site's password page); a re-approved seller who already
    // has a password just gets the sign-in button.
    $url = !$base ? null : ($code
        ? $base.'/forgot-password?'.http_build_query(['actor' => 'seller', 'email' => $seller->email, 'code' => $code, 'invite' => 1])
        : $base.'/seller');
@endphp

@section('title', 'Seller account approved')
@section('preheader', $code
    ? 'Your seller account is approved. Create your password to sign in.'
    : 'Your seller account is approved. Sign in to manage your store.')
@section('heading', 'Your seller account is approved')

@section('content')
    <p style="margin:0 0 16px;">Hello {{ $seller->seller_name }},</p>
    <p style="margin:0 0 16px;">Good news: your seller account for <strong>{{ $seller->store_name }}</strong> has been approved. You can now set up your store and add products.</p>
    @if ($code)
        <p style="margin:0 0 8px;">Create your password to get started:</p>
    @endif
    @if ($url)
        @include('emails.partials.button', ['url' => $url, 'label' => $code ? 'Create password' : 'Go to seller sign-in'])
    @endif
    @if ($code)
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">This link works for one day. After that, use Forgot password on the seller sign-in page with {{ $seller->email }}.</p>
    @endif
@endsection
