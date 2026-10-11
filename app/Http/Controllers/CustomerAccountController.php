<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerFollowedStore;
use App\Models\CustomerInSiteNotification;
use App\Models\CustomerRestockAlert;
use App\Models\CustomerWishlistItem;
use App\Models\Product;
use App\Models\ProductVariation;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CustomerAccountController extends Controller
{
    public function wishlist(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $items = CustomerWishlistItem::where('customer_id', $customer->id)
            ->with(['product:id,name,slug,thumbnail,default_selling_price,seller_id,status', 'product.seller:id,store_name,store_slug'])
            ->latest()->paginate(min(100, max(1, (int) $request->query('per_page', 25))));
        return response()->json($items);
    }

    public function addWishlist(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $product = Product::availableForSale()->findOrFail($data['product_id']);
        $item = CustomerWishlistItem::firstOrCreate(['customer_id' => $customer->id, 'product_id' => $product->id]);
        return response()->json($item->load(['product:id,name,slug,thumbnail,default_selling_price,seller_id,status', 'product.seller:id,store_name,store_slug']), 201);
    }

    public function removeWishlist(Request $request, Product $product): JsonResponse
    {
        CustomerWishlistItem::where('customer_id', $this->customer($request)->id)->where('product_id', $product->id)->delete();
        return response()->json(['message' => 'Product removed from wishlist.']);
    }

    public function followedStores(Request $request): JsonResponse
    {
        $rows = CustomerFollowedStore::where('customer_id', $this->customer($request)->id)
            ->with('seller:id,store_name,store_slug,store_logo,store_image,status,is_active')->latest()->paginate(100);
        return response()->json($rows);
    }

    public function followStore(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate(['seller_id' => ['required', 'integer']]);
        $seller = Seller::where('status', 'approved')->where('is_active', true)->findOrFail($data['seller_id']);
        $follow = CustomerFollowedStore::firstOrCreate(['customer_id' => $customer->id, 'seller_id' => $seller->id]);
        return response()->json($follow->load('seller:id,store_name,store_slug,store_logo,store_image,status,is_active'), 201);
    }

    public function unfollowStore(Request $request, Seller $seller): JsonResponse
    {
        CustomerFollowedStore::where('customer_id', $this->customer($request)->id)->where('seller_id', $seller->id)->delete();
        return response()->json(['message' => 'Store unfollowed.']);
    }

    public function restockAlerts(Request $request): JsonResponse
    {
        $rows = CustomerRestockAlert::where('customer_id', $this->customer($request)->id)
            ->with(['product:id,name,slug,thumbnail,seller_id,status', 'variation:id,product_id,sku,attributes,is_active'])
            ->latest()->paginate(100);
        return response()->json($rows);
    }

    public function addRestockAlert(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $data = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'variation_id' => ['nullable', 'integer', 'exists:product_variations,id'],
        ]);
        $product = Product::availableForSale()->findOrFail($data['product_id']);
        $variation = null;
        if ($product->product_type === 'variable') {
            if (empty($data['variation_id'])) throw ValidationException::withMessages(['variation_id' => ['Select a variation for this product.']]);
            $variation = ProductVariation::where('product_id', $product->id)->where('is_active', true)->findOrFail($data['variation_id']);
        } elseif (!empty($data['variation_id'])) {
            throw ValidationException::withMessages(['variation_id' => ['This simple product has no variations.']]);
        }
        $stock = (int) ($variation ? $variation->lots()->sum('quantity_remaining') : $product->lots()->sum('quantity_remaining'));
        if ($stock > 0) throw ValidationException::withMessages(['product_id' => ['This item is already in stock.']]);
        $key = $variation ? (string) $variation->id : 'simple';
        $alert = CustomerRestockAlert::updateOrCreate(
            ['customer_id' => $customer->id, 'product_id' => $product->id, 'variation_key' => $key],
            ['variation_id' => $variation?->id, 'notified_at' => null],
        );
        return response()->json($alert->load(['product:id,name,slug,thumbnail,seller_id,status', 'variation:id,product_id,sku,attributes,is_active']), 201);
    }

    public function removeRestockAlert(Request $request, CustomerRestockAlert $alert): JsonResponse
    {
        abort_unless((int) $alert->customer_id === (int) $this->customer($request)->id, 404);
        $alert->delete();
        return response()->json(['message' => 'Restock alert removed.']);
    }

    public function notifications(Request $request): JsonResponse
    {
        $customer = $this->customer($request);
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        return response()->json([
            'unread_count' => CustomerInSiteNotification::where('customer_id', $customer->id)->whereNull('read_at')->count(),
            'notifications' => CustomerInSiteNotification::where('customer_id', $customer->id)->latest()->paginate($perPage),
        ]);
    }

    public function markNotificationRead(Request $request, CustomerInSiteNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->customer_id === (int) $this->customer($request)->id, 404);
        $notification->update(['read_at' => $notification->read_at ?? now()]);
        return response()->json($notification->fresh());
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        CustomerInSiteNotification::where('customer_id', $this->customer($request)->id)->whereNull('read_at')->update(['read_at' => now()]);
        return response()->json(['message' => 'Notifications marked as read.']);
    }

    private function customer(Request $request): Customer
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        return $customer;
    }
}
