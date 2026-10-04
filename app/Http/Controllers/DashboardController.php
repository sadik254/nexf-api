<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\Review;
use App\Models\Seller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function admin(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        [$from, $to, $previousFrom, $previousTo] = $this->period($request);
        return response()->json($this->payload(null, $from, $to, $previousFrom, $previousTo));
    }

    public function seller(Request $request): JsonResponse
    {
        $seller = $request->user();
        abort_unless($seller instanceof Seller, 403);
        [$from, $to, $previousFrom, $previousTo] = $this->period($request);
        return response()->json($this->payload($seller, $from, $to, $previousFrom, $previousTo));
    }

    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now()->endOfDay();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'])->startOfDay() : $to->startOfMonth();
        abort_if($from->diffInDays($to) > 366, 422, 'Dashboard date range cannot exceed 367 days.');
        $days = $from->diffInDays($to) + 1;
        return [$from, $to, $from->subDays($days), $from->subDay()->endOfDay()];
    }

    private function payload(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $previousFrom, CarbonImmutable $previousTo): array
    {
        $orders = $this->orders($seller)->whereBetween('orders.created_at', [$from, $to]);
        $previousOrders = $this->orders($seller)->whereBetween('orders.created_at', [$previousFrom, $previousTo]);
        $revenue = $this->revenue($seller, $from, $to);
        $previousRevenue = $this->revenue($seller, $previousFrom, $previousTo);
        $orderCount = (clone $orders)->count();
        $previousOrderCount = (clone $previousOrders)->count();
        $productQuery = Product::query()->when($seller, fn (Builder $q) => $q->where('seller_id', $seller->id));
        $reviewQuery = Review::query()->where('status', 'approved')->whereHas('product', fn (Builder $q) => $seller ? $q->where('seller_id', $seller->id) : $q->whereNull('seller_id'));

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stats' => [
                'revenue' => round($revenue, 2),
                'revenue_change' => $this->change($revenue, $previousRevenue),
                'orders' => $orderCount,
                'orders_change' => $this->change($orderCount, $previousOrderCount),
                'average_order_value' => $orderCount ? round($revenue / $orderCount, 2) : 0,
                'products' => (clone $productQuery)->count(),
                'customers' => $seller ? null : Customer::count(),
                'new_customers' => $seller ? null : Customer::whereBetween('created_at', [$from, $to])->count(),
                'units_sold' => $this->items($seller)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))->sum('quantity'),
                'average_rating' => $seller ? round((float) $reviewQuery->avg('rating'), 1) : null,
                'review_count' => $seller ? $reviewQuery->count() : null,
            ],
            'sales_trend' => $this->trend($seller, $from, $to),
            'order_pipeline' => $this->pipeline($seller, $from, $to),
            'top_products' => $this->topProducts($seller, $from, $to),
            'top_sellers' => $seller ? [] : $this->topSellers($from, $to),
            'sales_breakdown' => $this->salesBreakdown($seller, $from, $to),
            'top_categories' => $this->topCategories($seller, $from, $to),
            'recent_orders' => $this->recentOrders($seller),
            'inventory_alerts' => $this->inventoryAlerts($productQuery),
        ];
    }

    private function orders(?Seller $seller): Builder
    {
        return Order::query()->when($seller, fn (Builder $q) => $q->whereHas('items', fn (Builder $items) => $items->where('seller_id', $seller->id)));
    }

    private function items(?Seller $seller): Builder
    {
        return OrderItem::query()->when($seller, fn (Builder $q) => $q->where('order_items.seller_id', $seller->id));
    }

    private function revenue(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): float
    {
        if (!$seller) return (float) Order::whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')->sum('total');
        return (float) OrderItem::where('seller_id', $seller->id)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))->sum('line_subtotal');
    }

    private function change(float|int $current, float|int $previous): ?float
    {
        if ((float) $previous === 0.0) return (float) $current === 0.0 ? 0 : null;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function trend(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = $seller
            ? OrderItem::where('seller_id', $seller->id)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))->with('order:id,created_at')->get()
            : Order::whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')->get(['created_at', 'total']);
        $daily = $items->groupBy(fn ($row) => ($seller ? $row->order->created_at : $row->created_at)->toDateString())
            ->map(fn ($rows) => round((float) $rows->sum($seller ? 'line_subtotal' : 'total'), 2));
        $span = $from->diffInDays($to);
        $step = $span > 62 ? 'month' : 'day';
        $points = [];
        for ($cursor = $from; $cursor <= $to; $cursor = $step === 'month' ? $cursor->addMonth()->startOfMonth() : $cursor->addDay()) {
            if ($step === 'month') {
                $key = $cursor->format('Y-m');
                $value = $daily->filter(fn ($v, $date) => str_starts_with($date, $key))->sum();
                $label = $cursor->format('M');
            } else {
                $value = $daily[$cursor->toDateString()] ?? 0;
                $label = $span <= 14 ? $cursor->format('D') : $cursor->format('M j');
            }
            $points[] = ['label' => $label, 'value' => round((float) $value, 2)];
        }
        return $points;
    }

    private function pipeline(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $statuses = ['pending', 'confirmed', 'shipped', 'delivered', 'completed', 'cancelled'];
        $counts = $this->orders($seller)->whereBetween('orders.created_at', [$from, $to])->select('status')->get()->countBy('status');
        return collect($statuses)->map(fn ($status) => ['status' => $status, 'label' => ucfirst($status), 'value' => (int) ($counts[$status] ?? 0)])->values()->all();
    }

    private function topProducts(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->items($seller)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))
            ->selectRaw('product_id, product_name, product_slug, product_thumbnail, sum(quantity) as units_sold, sum(line_subtotal) as revenue')
            ->groupBy('product_id', 'product_name', 'product_slug', 'product_thumbnail')->orderByDesc('units_sold')->limit(5)->get()->map(fn ($row) => [
                'product_id' => $row->product_id, 'name' => $row->product_name, 'slug' => $row->product_slug,
                'thumbnail' => $row->product_thumbnail, 'units_sold' => (int) $row->units_sold, 'revenue' => (float) $row->revenue,
            ])->all();
    }

    private function topSellers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $total = (float) OrderItem::whereNotNull('seller_id')->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))->sum('line_subtotal');
        return OrderItem::whereNotNull('seller_id')->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))
            ->selectRaw('seller_id, sum(quantity) as units_sold, sum(line_subtotal) as revenue')->groupBy('seller_id')->orderByDesc('revenue')->limit(5)->with('seller:id,store_name,store_slug,store_logo')->get()->map(fn ($row) => [
                'seller_id' => $row->seller_id, 'name' => $row->seller?->store_name, 'slug' => $row->seller?->store_slug, 'logo' => $row->seller?->store_logo,
                'units_sold' => (int) $row->units_sold, 'revenue' => (float) $row->revenue,
                'product_count' => Product::where('seller_id', $row->seller_id)->count(), 'share' => $total ? round(((float) $row->revenue / $total) * 100, 1) : 0,
            ])->all();
    }

    private function salesBreakdown(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $items = $this->items($seller)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'));
        $gross = (float) (clone $items)->sum('line_subtotal');
        $profit = (float) (clone $items)->sum('line_profit');
        $discounts = $seller ? 0 : (float) Order::whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')->sum('discount_total');
        $shipping = $seller ? 0 : (float) Order::whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled')->sum('shipping_charge');
        return ['gross_sales' => round($gross, 2), 'discounts' => round($discounts, 2), 'net_sales' => round($gross - $discounts, 2), 'shipping' => round($shipping, 2), 'total_sales' => round($gross - $discounts + $shipping, 2), 'profit' => round($profit, 2)];
    }

    private function topCategories(?Seller $seller, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->items($seller)->whereHas('order', fn (Builder $q) => $q->whereBetween('created_at', [$from, $to])->where('status', '!=', 'cancelled'))
            ->join('products', 'products.id', '=', 'order_items.product_id')
            ->join('product_categories', 'product_categories.id', '=', 'products.category_id')
            ->selectRaw('product_categories.id, product_categories.name, sum(order_items.quantity) as units_sold, sum(order_items.line_subtotal) as revenue')
            ->groupBy('product_categories.id', 'product_categories.name')->orderByDesc('revenue')->limit(6)->get()
            ->map(fn ($row) => ['category_id' => $row->id, 'name' => $row->name, 'units_sold' => (int) $row->units_sold, 'revenue' => (float) $row->revenue])->all();
    }

    private function recentOrders(?Seller $seller): array
    {
        return $this->orders($seller)->with(['items' => fn ($q) => $seller ? $q->where('seller_id', $seller->id) : $q, 'customer:id,name'])
            ->latest()->limit(5)->get()->map(function (Order $order) use ($seller) {
                $amount = $seller ? $order->items->sum(fn ($item) => (float) $item->line_subtotal) : (float) $order->total;
                $item = $order->items->first();
                return ['id' => $order->id, 'order_number' => $order->order_number, 'status' => $order->status, 'customer_name' => $order->customer?->name ?? $order->shipping_name, 'amount' => round($amount, 2), 'created_at' => $order->created_at, 'thumbnail' => $item?->product_image_variants['thumbnail']['thumb'] ?? $item?->product_thumbnail];
            })->all();
    }

    private function inventoryAlerts(Builder $productQuery): array
    {
        return $productQuery->withSum('lots as direct_stock', 'quantity_remaining')->with(['variations' => fn ($q) => $q->withSum('lots as stock', 'quantity_remaining')])->get()
            ->map(fn (Product $product) => ['product_id' => $product->id, 'name' => $product->name, 'slug' => $product->slug, 'thumbnail' => $product->image_variants['thumbnail']['thumb'] ?? $product->thumbnail, 'available_quantity' => $product->product_type === 'simple' ? (int) ($product->direct_stock ?? 0) : (int) $product->variations->sum('stock')])
            ->filter(fn ($row) => $row['available_quantity'] <= 3)->sortBy('available_quantity')->take(8)->values()->all();
    }
}
