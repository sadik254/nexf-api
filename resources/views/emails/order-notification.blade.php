Hello,

{{ $messageLine }}

Order: {{ $order->order_number }}
Status: {{ $order->status }}
Total: {{ $order->total }} {{ $order->shipping_currency }}

Items:
@foreach ($order->items as $item)
- {{ $item->product_name }} × {{ $item->quantity }} — {{ $item->line_subtotal }}
@endforeach
