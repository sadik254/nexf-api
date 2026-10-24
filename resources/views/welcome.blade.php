@extends('layouts.page')

@section('title', 'NEXF API')
@section('heading', 'NEXF API')
@section('description', "This server runs the NEXF store and console. There's nothing to browse here.")

@section('icon')
    <svg class="icon" viewBox="0 0 24 24" aria-hidden="true"><rect width="20" height="8" x="2" y="2" rx="2" ry="2"/><rect width="20" height="8" x="2" y="14" rx="2" ry="2"/><line x1="6" x2="6.01" y1="6" y2="6"/><line x1="6" x2="6.01" y1="18" y2="18"/></svg>
@endsection

@php
    $siteUrl = rtrim((string) config('app.frontend_url'), '/');
@endphp

@section('content')
    @if (session('status'))
        <p class="notice" role="status">{{ session('status') }}</p>
    @endif

    @if ($siteUrl)
        <div class="stack">
            <a href="{{ $siteUrl }}/dashboard" class="btn btn-primary">Sign in to the console</a>
            <a href="{{ $siteUrl }}" class="btn btn-outline">Go to the store</a>
        </div>
    @endif
@endsection
