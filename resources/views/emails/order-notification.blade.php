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
        default => ['#f4f4f5', '#3f3f46'],
    };
    // Lines snapshot raw option keys ({"vg-1": "white"}); the product's option groups name them ("Color: White").
    $variant = function ($item) {
        $attributes = $item->variation_attributes;
        if (!is_array($attributes) || !$attributes) return '';
        $groups = collect($item->product?->option_groups ?? [])->keyBy('key');
        // Follow the product's option order (as the cart does), not the order the line saved them in.
        $position = $groups->keys()->flip();
        return collect($attributes)->sortBy(fn ($value, $key) => $position[$key] ?? PHP_INT_MAX)->map(function ($value, $key) use ($groups) {
            if (is_array($value)) {
                $name = $value['name'] ?? $value['attribute'] ?? null;
                $val = $value['value'] ?? $value['option'] ?? null;
                return $name && $val ? "{$name}: {$val}" : ($val ?? $name);
            }
            $group = $groups->get($key);
            $label = collect($group['values'] ?? [])->firstWhere('value', (string) $value)['label'] ?? ucfirst((string) $value);
            if ($group) return "{$group['label']}: {$label}";
            return is_string($key) && !preg_match('/^vg-\d+$/i', $key) ? ucwords(str_replace('_', ' ', $key)).": {$label}" : $label;
        })->filter()->implode(' · ');
    };
    // The saved address line already ends with the thana and district; drop that tail so they show once, on their own line.
    $locality = collect([$order->shipping_area, $order->shipping_district])->filter()->unique()->implode(', ');
    $street = trim((string) $order->shipping_address);
    foreach ([$order->shipping_district, $order->shipping_area] as $part) {
        if ($part && preg_match('/(?:^|[,\s]+)'.preg_quote(trim($part), '/').'$/iu', $street, $m)) {
            $street = rtrim(substr($street, 0, -strlen($m[0])), ", \t");
        }
    }
    $address = $street || $locality;
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
@section('heading', $heading ?? $subjectLine)

@section('content')
    @if ($isStaff)
        <p style="margin:0 0 20px;">{{ $messageLine }} Placed {{ $order->created_at?->timezone('Asia/Dhaka')->format('j M Y, g:i A') ?? 'just now' }}.</p>
    @else
        <p style="margin:0 0 16px;">Hello{{ $order->shipping_name ? ' '.$order->shipping_name : '' }},</p>
        <p style="margin:0 0 20px;">{{ $messageLine }}</p>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;">
        <tr>
            <td style="padding:14px 18px;">
                <div style="font-size:12px;line-height:16px;font-weight:500;color:#71717a;">Order</div>
                <div style="font-size:16px;line-height:24px;font-weight:600;color:#09090b;">{{ $order->order_number }}</div>
            </td>
            <td align="right" style="padding:14px 18px;">
                <span style="display:inline-block;padding:2px 8px;border-radius:999px;background:{{ $statusColors[0] }};color:{{ $statusColors[1] }};font-size:12px;line-height:16px;font-weight:500;">{{ $statusLabel }}</span>
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
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#fafafa;border:1px solid #e4e4e7;border-radius:10px;">
            <tr>
                <td style="padding:14px 18px 4px;font-size:12px;line-height:16px;font-weight:500;color:#71717a;">Tracking</td>
            </tr>
            @foreach ($parcels as $parcel)
                @php
                    $courier = $parcel->courier_provider ? ucfirst($parcel->courier_provider) : 'Courier';
                    $trackUrl = str_contains(strtolower((string) $parcel->courier_provider), 'steadfast')
                        ? 'https://steadfast.com.bd/t/'.rawurlencode($parcel->tracking_number) : null;
                @endphp
                <tr>
                    <td style="padding:2px 18px {{ $loop->last ? '14px' : '6px' }};font-size:14px;line-height:21px;color:#09090b;">
                        {{ $courier }}: <strong style="font-family:'SFMono-Regular',Consolas,'Liberation Mono',Menlo,monospace;">{{ $parcel->tracking_number }}</strong>
                        @if ($trackUrl)
                            &nbsp;<a href="{{ $trackUrl }}" style="color:#066fff;font-weight:600;">Track parcel</a>
                        @endif
                        @if ($parcel->courier_tracking_message)
                            <div style="font-size:13px;line-height:19px;color:#52525b;">{{ $parcel->courier_tracking_message }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 4px;">
        @foreach ($items as $item)
            @php $variantText = $variant($item); @endphp
            <tr>
                <td style="padding:12px 0;border-bottom:1px solid #e4e4e7;vertical-align:top;">
                    <div style="font-size:14px;line-height:20px;font-weight:600;color:#09090b;">{{ $item->product_name }}</div>
                    <div style="font-size:13px;line-height:18px;color:#71717a;">
                        @if ($variantText){{ $variantText }} &middot; @endif Qty {{ $item->quantity }}
                    </div>
                </td>
                <td align="right" style="padding:12px 0 12px 12px;border-bottom:1px solid #e4e4e7;vertical-align:top;white-space:nowrap;font-size:14px;line-height:20px;color:#09090b;">{{ $money($item->line_subtotal) }}</td>
            </tr>
        @endforeach
    </table>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;font-size:14px;line-height:20px;color:#52525b;">
    @if ($audience === 'seller')
        @php
            // Use the same parcel values available to the seller's order page.
            // A shared-delivery parcel can carry this seller's lines while naming
            // another store as the delivery-group owner.
            $storeGroups = $order->storeGroups()
                ->where(fn ($query) => $query->where('seller_id', $sellerId)->orWhereHas('items', fn ($items) => $items->where('seller_id', $sellerId)))
                ->get();
            $storeGroup = $storeGroups->first(fn ($group) => (int) $group->seller_id === (int) $sellerId) ?? $storeGroups->first();
            $storeSubtotal = round((float) $items->sum('line_subtotal'), 2);
            $storeShipping = (float) ($storeGroup?->shipping_charge ?? 0);
            $storeMethod = $storeGroup?->shipping_method_name ?? $order->shipping_method_name;
            $storeDiscount = (float) ($storeGroup?->discount_share ?? 0);
            $storeTotal = round($storeSubtotal + $storeShipping - $storeDiscount, 2);
            $storePrepaid = min($storeTotal, round((float) $storeGroups->sum('prepaid_amount'), 2));
        @endphp
        <tr><td style="padding:10px 0 3px;">Subtotal</td><td align="right" style="padding:10px 0 3px;">{{ $money($storeSubtotal) }}</td></tr>
        <tr><td style="padding:3px 0;">Delivery{{ $storeMethod ? ' ('.$storeMethod.')' : '' }}</td><td align="right" style="padding:3px 0;">{{ $storeShipping > 0 ? $money($storeShipping) : 'Free' }}</td></tr>
        @if ($storeDiscount > 0)
            <tr><td style="padding:3px 0;color:#047857;">{{ $order->coupon_code ? 'Coupon ('.$order->coupon_code.')' : 'Discount' }}</td><td align="right" style="padding:3px 0;color:#047857;">−{{ $money($storeDiscount) }}</td></tr>
        @endif
        @if ($storePrepaid > 0)
            <tr><td style="padding:3px 0;color:#047857;">Paid in advance</td><td align="right" style="padding:3px 0;color:#047857;">−{{ $money($storePrepaid) }}</td></tr>
        @endif
        <tr>
            <td style="padding:10px 0 0;border-top:1px solid #e4e4e7;font-size:16px;line-height:24px;font-weight:600;color:#09090b;">Total</td>
            <td align="right" style="padding:10px 0 0;border-top:1px solid #e4e4e7;font-size:16px;line-height:24px;font-weight:600;color:#09090b;">{{ $money($storeTotal - $storePrepaid) }}</td>
        </tr>
    @else
        @if ($order->subtotal !== null)
            <tr><td style="padding:10px 0 3px;">Subtotal</td><td align="right" style="padding:10px 0 3px;">{{ $money($order->subtotal) }}</td></tr>
        @endif
        @if ($order->shipping_charge !== null)
            <tr><td style="padding:3px 0;">Delivery{{ $order->shipping_method_name ? ' ('.$order->shipping_method_name.')' : '' }}</td><td align="right" style="padding:3px 0;">{{ (float) $order->shipping_charge > 0 ? $money($order->shipping_charge) : 'Free' }}</td></tr>
        @endif
        @if ((float) $order->discount_total > 0)
            <tr><td style="padding:3px 0;color:#047857;">{{ $order->coupon_code ? 'Coupon ('.$order->coupon_code.')' : 'Discount' }}</td><td align="right" style="padding:3px 0;color:#047857;">−{{ $money($order->discount_total) }}</td></tr>
        @endif
        @php($walletPart = (float) $order->wallet_amount > 0 && (float) $order->wallet_amount < (float) $order->total ? (float) $order->wallet_amount : 0)
        @if ($walletPart > 0)
            <tr><td style="padding:3px 0;color:#047857;">NEXF Balance</td><td align="right" style="padding:3px 0;color:#047857;">−{{ $money($walletPart) }}</td></tr>
        @endif
        <tr>
            <td style="padding:10px 0 0;border-top:1px solid #e4e4e7;font-size:16px;line-height:24px;font-weight:600;color:#09090b;">Total</td>
            <td align="right" style="padding:10px 0 0;border-top:1px solid #e4e4e7;font-size:16px;line-height:24px;font-weight:600;color:#09090b;">{{ $money((float) $order->total - $walletPart) }}</td>
        </tr>
    @endif
    </table>

    @if ($order->shipping_name || $address || $order->payment_method_name)
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;">
            <tr>
                @if ($order->shipping_name || $address)
                    <td style="vertical-align:top;padding-right:12px;font-size:13px;line-height:20px;color:#52525b;">
                        <div style="font-size:12px;line-height:16px;font-weight:500;color:#71717a;padding-bottom:4px;">Delivery</div>
                        @if ($order->shipping_name)<div style="color:#09090b;font-weight:600;">{{ $order->shipping_name }}</div>@endif
                        @if ($order->shipping_phone)<div>{{ $order->shipping_phone }}</div>@endif
                        @if ($street)<div>{{ $street }}</div>@endif
                        @if ($locality)<div>{{ $locality }}</div>@endif
                    </td>
                @endif
                @if ($order->payment_method_name)
                    <td align="right" style="vertical-align:top;text-align:right;font-size:13px;line-height:20px;color:#52525b;">
                        <div style="font-size:12px;line-height:16px;font-weight:500;color:#71717a;padding-bottom:4px;">Payment</div>
                        <div style="color:#09090b;font-weight:600;">{{ $order->payment_method_name }}</div>
                        @if ($order->payment_status)<div>{{ ucwords(str_replace('_', ' ', $order->payment_status)) }}</div>@endif
                    </td>
                @endif
            </tr>
        </table>
    @endif

    @if ($ordersUrl)
        @include('emails.partials.button', ['url' => $ordersUrl, 'label' => 'View order'])
    @endif
@endsection
