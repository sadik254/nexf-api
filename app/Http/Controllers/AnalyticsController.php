<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\AnalyticsEvent;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnalyticsController extends Controller
{
    public function track(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string', 'max:100'], 'visitor_id' => ['nullable', 'string', 'max:100'],
            'seller_id' => ['nullable', 'integer', 'exists:sellers,id'], 'store_type' => ['nullable', Rule::in(['house'])], 'event' => ['required', Rule::in(AnalyticsEvent::EVENTS)],
            'page' => ['nullable', 'string', 'max:500'], 'channel' => ['nullable', 'string', 'max:80'],
            'referrer' => ['nullable', 'string', 'max:500'], 'device' => ['nullable', 'string', 'max:40'], 'location' => ['nullable', 'string', 'max:120'],
        ]);
        $location = trim((string) ($data['location'] ?? ''));
        AnalyticsEvent::create(array_merge($data, [
            'location' => $location !== '' ? $location : $this->locationFromRequest($request),
            'ip' => $request->ip(),
        ]));
        return response()->json(['ok' => true], 201);
    }

    public function admin(Request $request): JsonResponse
    {
        $data = $request->validate(['seller_id' => ['nullable', 'integer', 'exists:sellers,id'], 'store' => ['nullable', Rule::in(['all', 'house'])], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        return response()->json($this->summary($data, $data['seller_id'] ?? null, ($data['store'] ?? 'all') === 'house'));
    }

    public function seller(Request $request): JsonResponse
    {
        $seller = $request->user(); abort_unless($seller instanceof Seller, 403);
        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        return response()->json($this->summary($data, $seller->id, false));
    }

    private function summary(array $data, ?int $sellerId, bool $house): array
    {
        $from = isset($data['from']) ? now()->parse($data['from'])->startOfDay() : now()->subDays(29)->startOfDay();
        $to = isset($data['to']) ? now()->parse($data['to'])->endOfDay() : now()->endOfDay();
        $events = AnalyticsEvent::query()->whereBetween('created_at', [$from, $to])
            ->when($sellerId !== null, fn ($q) => $q->where('seller_id', $sellerId));
        if ($house) {
            $events->where(function ($query): void {
                // New events identify the house explicitly. The narrow fallback
                // keeps historic house product/conversion traffic useful while
                // excluding homepage, search, and Offers page views.
                $query->where('store_type', 'house')->orWhere(function ($legacy): void {
                    $legacy->whereNull('store_type')->whereNull('seller_id')
                        ->whereIn('event', ['product_view', 'add_to_cart', 'checkout_started', 'order_completed']);
                });
            });
        }
        $rows = $events->get();
        $sessions = $rows->pluck('session_id')->unique()->count();
        $orders = $rows->where('event', 'order_completed')->pluck('session_id')->unique()->count();
        $funnel = collect(['page_view' => 'Sessions', 'product_view' => 'Product views', 'add_to_cart' => 'Added to cart', 'checkout_started' => 'Checkout', 'order_completed' => 'Orders'])->map(fn ($label, $event) => ['label' => $label, 'value' => $event === 'page_view' ? $sessions : $rows->where('event', $event)->pluck('session_id')->unique()->count()])->values();
        $breakdown = fn (string $field) => $rows->filter(fn ($row) => filled($row->{$field}))->groupBy($field)->map(fn ($group, $name) => ['name' => $name, 'value' => $group->pluck('session_id')->unique()->count()])->sortByDesc('value')->values();
        $returning = $rows->filter(fn ($row) => $row->visitor_id)->groupBy('visitor_id')->filter(fn ($group) => $group->pluck('session_id')->unique()->count() > 1)->count();
        $storeScoped = $sellerId !== null || $house;
        return ['period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'sessions' => $sessions,
            'session_metric' => $storeScoped ? 'store_engaged_sessions' : 'site_sessions',
            'session_label' => $storeScoped ? 'Store visits' : 'Site sessions',
            'orders' => $orders,
            'conversion' => $sessions ? round($orders / $sessions * 100, 2) : 0, 'returning_customers' => $returning,
            'funnel' => $funnel, 'channels' => $breakdown('channel'), 'devices' => $breakdown('device'), 'locations' => $breakdown('location'), 'referrers' => $breakdown('referrer')];
    }

    private function locationFromRequest(Request $request): ?string
    {
        // Cloudflare sends CF-IPCity as URL-encoded text. The extra names make
        // local/proxied deployments work with their equivalent geo headers.
        foreach (['CF-IPCity', 'X-Geo-City', 'X-Vercel-IP-City', 'X-Appengine-City'] as $header) {
            $value = trim(rawurldecode((string) $request->header($header, '')));
            if ($value !== '') return mb_substr($value, 0, 120);
        }
        $country = trim((string) $request->header('CF-IPCountry', ''));
        return $country !== '' && $country !== 'XX' ? mb_substr($country, 0, 120) : null;
    }
}
