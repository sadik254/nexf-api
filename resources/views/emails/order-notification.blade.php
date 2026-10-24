@extends('emails.layout')
@php
    $currency = strtoupper((string) ($order->shipping_currency ?: 'BDT'));
    $money = fn ($value) => ($currency === 'BDT' ? '৳' : $currency.' ').number_format((float) $value, 2);
    $statusLabel = ucwords(str_replace('_', ' ', (string) $order->status));
    $statusColors = match ($order->status) {
        'cancelled' => ['#fef2f2', '#b91c1c'],
        'delivered', 'completed' => ['#ecfdf5', '#047857'],
        'shipped' => ['#eff6ff', '#1d4ed8'],
        'return_pending' => ['#fff7ed', '#c2410c'],
        default => ['#f3f4f6', '#374151'],
    };
    $variant = function ($attributes) {
        if (!is_array($attributes) || !$attributes) return '';
        return collect($attributes)->map(function ($value, $key) {
            if (is_array($value)) {
                $name = $value['name'] ?? $value['attribute'] ?? null;
                $val = $value['value'] ?? $value['option'] ?? null;
                return $name && $val ? "{$name}: {$val}" : ($val ?? $name);
            }
            return is_string($key) ? "{$key}: {$value}" : $value;
        })->filter()->implode(' · ');
    };
    $address = collect([$order->shipping_address, $order->shipping_area, $order->shipping_district])->filter()->implode(', ');
    $frontend = rtrim((string) config('app.frontend_url'), '/');
    $audience = $audience ?? 'customer';
    $isStaff = $audience !== 'customer';
    // A seller only sees their own lines, never other stores' items or the order-level totals.
    $items = $audience === 'seller' ? $order->items->where('seller_id', $sellerId)->values() : $order->items;
    $ordersUrl = !$frontend ? null : match (true) {
        $isStaff => $frontend.'/dashboard/orders/'.rawurlencode($order->order_number),
        (bool) $order->customer_id => $frontend.'/account/orders/'.rawurlencode($order->order_number),
        default => null,
    };
@endphp

@section('title', $subjectLine)
@section('preheader', $messageLine.' Order '.$order->order_number.'.')
@section('heading', $subjectLine)

@section('content')
    @if ($isStaff)
        <p style="margin:0 0 20px;">{{ $messageLine }} Placed {{ $order->created_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A') ?? 'just now' }}.</p>
    @else
        <p style="margin:0 0 16px;">Hello{{ $order->shipping_name ? ' '.$order->shipping_name : '' }},</p>
        <p style="margin:0 0 20px;">{{ $messageLine }}</p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;">
        <tr>
            <td style="padding:14px 18px;">
                <div style="font-size:12px;line-height:16px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:#6b7280;">Order</div>
                <div style="font-size:16px;line-height:24px;font-weight:700;color:#111827;">{{ $order->order_number }}</div>
            </td>
            <td align="right" style="padding:14px 18px;">
                <span style="display:inline-block;padding:4px 12px;border-radius:999px;background:{{ $statusColors[0] }};color:{{ $statusColors[1] }};font-size:13px;line-height:18px;font-weight:600;">{{ $statusLabel }}</span>
            </td>
        </tr>
    </table>

    @php
        // One row per parcel; a multi-store order can ship in several.
        $parcels = in_array($order->status, ['shipped', 'delivered'], true)
            ? $items->filter(fn ($item) => $item->tracking_number)->unique('tracking_number')->values()
            : collect();
    @endphp
    @if ($parcels->isNotEmpty())
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;">
            <tr>
                <td style="padding:14px 18px 4px;font-size:12px;line-height:16px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:#1e3a8a;">Tracking</td>
            </tr>
            @foreach ($parcels as $parcel)
                @php
                    $courier = $parcel->courier_provider ? ucfirst($parcel->courier_provider) : 'Courier';
                    $trackUrl = str_contains(strtolower((string) $parcel->courier_provider), 'steadfast')
                        ? 'https://steadfast.com.bd/t/'.rawurlencode($parcel->tracking_number) : null;
                @endphp
                <tr>
                    <td style="padding:2px 18px {{ $loop->last ? '14px' : '6px' }};font-size:14px;line-height:21px;color:#111827;">
                        {{ $courier }}: <strong style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;">{{ $parcel->tracking_number }}</strong>
                        @if ($trackUrl)
                            &nbsp;<a href="{{ $trackUrl }}" style="color:#1d4ed8;font-weight:600;">Track parcel</a>
                        @endif
                        @if ($parcel->courier_tracking_message)
                            <div style="font-size:13px;line-height:19px;color:#4b5563;">{{ $parcel->courier_tracking_message }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;">
        @foreach ($items as $item)
            @php $variantText = $variant($item->variation_attributes); @endphp
            <tr>
                <td style="padding:12px 0;border-bottom:1px solid #f3f4f6;vertical-align:top;">
                    <div style="font-size:14px;line-height:20px;font-weight:600;color:#111827;">{{ $item->product_name }}</div>
                    <div style="font-size:13px;line-height:18px;color:#6b7280;">
                        Qty {{ $item->quantity }}@if ($variantText) &middot; {{ $variantText }}@endif
                    </div>
                </td>
                <td align="right" style="padding:12px 0 12px 12px;border-bottom:1px solid #f3f4f6;vertical-align:top;white-space:nowrap;font-size:14px;line-height:20px;color:#111827;">{{ $money($item->line_subtotal) }}</td>
            </tr>
        @endforeach
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;font-size:14px;line-height:20px;color:#4b5563;">
    @if ($audience === 'seller')
        <tr>
            <td style="padding:10px 0 0;font-size:16px;line-height:24px;font-weight:700;color:#111827;">Your items</td>
            <td align="right" style="padding:10px 0 0;font-size:16px;line-height:24px;font-weight:700;color:#111827;">{{ $money($items->sum('line_subtotal')) }}</td>
        </tr>
    @else
        @if ($order->subtotal !== null)
            <tr><td style="padding:10px 0 3px;">Subtotal</td><td align="right" style="padding:10px 0 3px;">{{ $money($order->subtotal) }}</td></tr>
        @endif
        @if ($order->shipping_charge !== null)
            <tr><td style="padding:3px 0;">Shipping{{ $order->shipping_method_name ? ' ('.$order->shipping_method_name.')' : '' }}</td><td align="right" style="padding:3px 0;">{{ (float) $order->shipping_charge > 0 ? $money($order->shipping_charge) : 'Free' }}</td></tr>
        @endif
        @if ((float) $order->discount_total > 0)
            <tr><td style="padding:3px 0;">Discount{{ $order->coupon_code ? ' ('.$order->coupon_code.')' : '' }}</td><td align="right" style="padding:3px 0;color:#047857;">−{{ $money($order->discount_total) }}</td></tr>
        @endif
        <tr>
            <td style="padding:10px 0 0;border-top:1px solid #e5e7eb;font-size:16px;line-height:24px;font-weight:700;color:#111827;">Total</td>
            <td align="right" style="padding:10px 0 0;border-top:1px solid #e5e7eb;font-size:16px;line-height:24px;font-weight:700;color:#111827;">{{ $money($order->total) }}</td>
        </tr>
    @endif
    </table>

    @if ($order->shipping_name || $address || $order->payment_method_name)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
            <tr>
                @if ($order->shipping_name || $address)
                    <td style="vertical-align:top;padding-right:12px;font-size:13px;line-height:20px;color:#4b5563;">
                        <div style="font-size:12px;line-height:16px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:#6b7280;padding-bottom:4px;">Delivery</div>
                        @if ($order->shipping_name)<div style="color:#111827;font-weight:600;">{{ $order->shipping_name }}</div>@endif
                        @if ($order->shipping_phone)<div>{{ $order->shipping_phone }}</div>@endif
                        @if ($isStaff && $order->shipping_email)<div>{{ $order->shipping_email }}</div>@endif
                        @if ($address)<div>{{ $address }}</div>@endif
                    </td>
                @endif
                @if ($order->payment_method_name)
                    <td style="vertical-align:top;font-size:13px;line-height:20px;color:#4b5563;">
                        <div style="font-size:12px;line-height:16px;font-weight:600;text-transform:uppercase;letter-spacing:0.6px;color:#6b7280;padding-bottom:4px;">Payment</div>
                        <div style="color:#111827;font-weight:600;">{{ $order->payment_method_name }}</div>
                        @if ($order->payment_status)<div>{{ ucwords(str_replace('_', ' ', $order->payment_status)) }}</div>@endif
                    </td>
                @endif
            </tr>
        </table>
    @endif

    @if ($ordersUrl)
        @include('emails.partials.button', ['url' => $ordersUrl, 'label' => $isStaff ? 'Open order' : 'View order'])
    @endif
@endsection
