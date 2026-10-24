@extends('emails.layout')
{{--
    One template for the simple notices (NoticeMail). Every key is optional except heading:
    greeting, lines[], rows[label => value], quote (a message body), callout + tone (info|danger|success),
    button [label, url], footnote.
--}}
@php
    $callouts = [
        'info' => ['#eff6ff', '#bfdbfe', '#1e3a8a'],
        'danger' => ['#fef2f2', '#fecaca', '#7f1d1d'],
        'success' => ['#ecfdf5', '#a7f3d0', '#065f46'],
    ];
    [$calloutBg, $calloutBorder, $calloutText] = $callouts[$tone ?? 'info'] ?? $callouts['info'];
@endphp

@section('title', $heading)
@section('preheader', $preheader ?? ($lines[0] ?? $heading))
@section('heading', $heading)

@section('content')
    @if (!empty($greeting))
        <p style="margin:0 0 16px;">{{ $greeting }}</p>
    @endif
    @foreach ($lines ?? [] as $line)
        <p style="margin:0 0 16px;">{{ $line }}</p>
    @endforeach

    @if (!empty($quote))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
            <tr>
                <td style="border-left:3px solid #056fff;background:#f9fafb;border-radius:0 8px 8px 0;padding:12px 16px;font-size:14px;line-height:21px;color:#374151;white-space:pre-line;">{{ $quote }}</td>
            </tr>
        </table>
    @endif

    @if (!empty($rows))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;font-size:14px;line-height:20px;">
            @foreach ($rows as $label => $value)
                <tr>
                    <td style="padding:{{ $loop->first ? '14px' : '4px' }} 20px {{ $loop->last ? '14px' : '4px' }};color:#6b7280;white-space:nowrap;vertical-align:top;">{{ $label }}</td>
                    <td align="right" style="padding:{{ $loop->first ? '14px' : '4px' }} 20px {{ $loop->last ? '14px' : '4px' }};color:#111827;font-weight:600;word-break:break-word;">{{ $value }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (!empty($callout))
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:{{ $calloutBg }};border:1px solid {{ $calloutBorder }};border-radius:10px;">
            <tr>
                <td style="padding:14px 20px;font-size:14px;line-height:21px;color:{{ $calloutText }};">{{ $callout }}</td>
            </tr>
        </table>
    @endif

    @if (!empty($button['url']))
        @include('emails.partials.button', ['url' => $button['url'], 'label' => $button['label']])
    @endif

    @if (!empty($footnote))
        <p style="margin:0;font-size:13px;line-height:20px;color:#6b7280;">{{ $footnote }}</p>
    @endif
@endsection
