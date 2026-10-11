<?php

namespace App\Services;

use App\Models\CustomerInSiteNotification;
use App\Models\CustomerRestockAlert;
use App\Models\Product;
use App\Models\ProductVariation;

class RestockAlertService
{
    public function notify(int $productId, ?int $variationId = null): void
    {
        $product = Product::availableForSale()->find($productId);
        if (!$product) return;
        $variation = $variationId ? ProductVariation::where('product_id', $productId)->where('is_active', true)->find($variationId) : null;
        if ($variationId && !$variation) return;
        $alerts = CustomerRestockAlert::query()->where('product_id', $productId)->where('variation_id', $variationId)->whereNull('notified_at')->with('customer:id')->get();
        foreach ($alerts as $alert) {
            CustomerInSiteNotification::create([
                'customer_id' => $alert->customer_id,
                'type' => 'restock',
                'title' => 'Back in stock',
                'body' => $variation ? "{$product->name} is back in stock." : "{$product->name} is back in stock.",
                'data' => ['product_id' => $productId, 'variation_id' => $variationId, 'product_slug' => $product->slug],
            ]);
            $alert->update(['notified_at' => now()]);
        }
    }
}
