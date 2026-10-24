@extends('emails.layout')
@php
    $base = rtrim((string) config('app.frontend_url'), '/');
    // Staff sign in through the seller login, so the page runs as `seller`.
    // `invite=1` makes it read "Create your password" rather than a reset.
    $url = $base
        ? $base.'/forgot-password?'.http_build_query(['actor' => 'seller', 'email' => $email, 'code' => $code, 'invite' => 1])
        : null;
@endphp

@section('title', 'You\'ve been added to '.$storeName)
@section('preheader', 'Create your password to start helping run '.$storeName.' on NEXF.')
@section('heading', 'You\'ve been added to '.$storeName)

@section('content')
    <p style="margin:0 0 16px;">Hello {{ $name }},</p>
    <p style="margin:0 0 16px;">You've been added as staff for <strong>{{ $storeName }}</strong> on NEXF. You'll be able to manage the store's orders, products, stock, discounts and support.</p>
    <p style="margin:0 0 8px;">Create your password to get started:</p>
    @if ($url)
        @include('emails.partials.button', ['url' => $url, 'label' => 'Create password'])
    @endif
    <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">This link works for seven days. After that, use Forgot password on the seller sign-in page with this email. If you weren't expecting this, you can ignore this email.</p>
@endsection
