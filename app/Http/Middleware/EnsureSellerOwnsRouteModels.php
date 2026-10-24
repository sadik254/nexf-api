<?php

namespace App\Http\Middleware;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\ProductVariation;
use App\Models\Seller;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Applies the store boundary before a seller controller sees a route model.
 *
 * Lists are already constrained in their controllers.  This protects detail
 * routes too, and deliberately answers 404 so a store cannot probe another
 * store's record ids.
 */
class EnsureSellerOwnsRouteModels
{
    public function handle(Request $request, Closure $next)
    {
        $seller = $request->user();

        if (! $seller instanceof Seller) {
            abort(404);
        }

        foreach ($request->route()?->parameters() ?? [] as $model) {
            if ($model instanceof Model && ! $this->belongsToSeller($model, $seller->id)) {
                abort(404);
            }
        }

        return $next($request);
    }

    private function belongsToSeller(Model $model, int $sellerId): bool
    {
        if ($model instanceof Seller) {
            return $model->id === $sellerId;
        }

        if (array_key_exists('seller_id', $model->getAttributes())) {
            return (int) $model->getAttribute('seller_id') === $sellerId;
        }

        if ($model instanceof Order) {
            return $model->items()->where('seller_id', $sellerId)->exists();
        }

        if ($model instanceof OrderItem) {
            return (int) $model->seller_id === $sellerId;
        }

        if ($model instanceof ProductVariation) {
            return $model->product()->where('seller_id', $sellerId)->exists();
        }

        if ($model instanceof Review || $model instanceof ProductQuestion) {
            return $model->product()->where('seller_id', $sellerId)->exists();
        }

        // Route models without a store owner are shared configuration records.
        return true;
    }
}
