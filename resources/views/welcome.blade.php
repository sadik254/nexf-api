@extends('layouts.page')
@section('title', 'NEXF API')
@section('content')
<div class="card">
    <p class="eyebrow">NEXF Lifestyle</p>
    <h1 class="title">NEXF API</h1>
    @if (session('status'))<div class="success">{{ session('status') }}</div>@else<p class="lead">Your store is set up and the API is ready.</p>@endif
    @if (config('app.frontend_url'))<div class="actions"><a class="button" href="{{ rtrim(config('app.frontend_url'), '/') }}/csxadmin">Sign in to the console</a>&nbsp;<a class="button" href="{{ config('app.frontend_url') }}">Go to the store</a></div>@endif
</div>
@endsection
