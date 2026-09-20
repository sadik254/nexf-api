<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\Seller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductEngagementController extends Controller
{
    public function reviewsForCustomer(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        return response()->json(Review::query()
            ->where('customer_id', $customer->id)
            ->with('product:id,name,slug,thumbnail,gallery')
            ->latest()->paginate($perPage));
    }

    public function reviewableItems(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        return response()->json(OrderItem::query()
            ->where('fulfillment_status', 'delivered')
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customer->id))
            ->whereDoesntHave('review')
            ->latest()->paginate($perPage));
    }

    public function questionsForCustomer(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        $questions = ProductQuestion::query()->where('customer_id', $customer->id)
            ->with('product:id,name,slug,thumbnail,gallery')->latest()->paginate($perPage);
        $questions->getCollection()->transform(fn (ProductQuestion $question) => [
            ...$this->questionPayload($question),
            'product' => $question->product,
        ]);
        return response()->json($questions);
    }

    public function reviewsForAdmin(Request $request): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected']])['status'] ?? 'pending';
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        return response()->json(Review::with(['customer:id,name', 'product:id,name,slug'])->where('status', $status)->latest()->paginate($perPage));
    }

    public function moderateReview(Review $review, Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected']]);
        $review->update(['status' => $data['status']]);
        return response()->json(['message' => "Review {$data['status']} successfully.", 'review' => $review->fresh(['customer:id,name', 'product:id,name,slug'])]);
    }

    public function questions(Product $product, Request $request): JsonResponse
    {
        abort_unless($product->status === 'active', 404);
        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        $questions = $product->questions()->with('customer:id,name')->latest()->paginate($perPage);
        $questions->getCollection()->transform(fn (ProductQuestion $question) => $this->questionPayload($question));
        return response()->json($questions);
    }

    public function ask(Product $product, Request $request): JsonResponse
    {
        abort_unless($product->status === 'active', 404);
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:1000']]);
        $question = $product->questions()->create(['customer_id' => $customer->id, 'question' => $data['question']]);
        return response()->json(['message' => 'Question posted successfully.', 'question' => $this->questionPayload($question->load('customer:id,name'))], 201);
    }

    public function answer(ProductQuestion $question, Request $request): JsonResponse
    {
        $actor = $request->user();
        if ($actor instanceof Seller && (int) $question->product()->value('seller_id') !== (int) $actor->id) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        if (!$actor instanceof Seller && !$actor instanceof Admin) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }
        $data = $request->validate(['answer' => ['required', 'string', 'min:2', 'max:2000']]);
        $question->update(['answer' => $data['answer'], 'answered_by_type' => $actor instanceof Seller ? 'seller' : 'admin', 'answered_by_id' => $actor->id, 'answered_at' => now()]);
        return response()->json(['message' => 'Answer saved successfully.', 'question' => $this->questionPayload($question->fresh('customer:id,name'))]);
    }

    public function review(Product $product, Request $request): JsonResponse
    {
        abort_unless($product->status === 'active', 404);
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['order_item_id' => ['sometimes', 'integer', 'exists:order_items,id'], 'rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:3000']]);
        $item = OrderItem::query()
            ->when(isset($data['order_item_id']), fn ($query) => $query->whereKey($data['order_item_id']))
            ->where('product_id', $product->id)
            ->where('fulfillment_status', 'delivered')
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customer->id))
            ->latest('id')
            ->first();
        if (!$item) return response()->json(['message' => 'Only customers with a delivered purchase can review this product.'], 422);
        $review = Review::updateOrCreate(['customer_id' => $customer->id, 'order_item_id' => $item->id], ['product_id' => $product->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? null, 'status' => 'pending']);
        return response()->json(['message' => 'Review submitted for approval.', 'review' => $review], $review->wasRecentlyCreated ? 201 : 200);
    }

    private function questionPayload(ProductQuestion $question): array
    {
        $answeredBy = null;
        if ($question->answered_by_type === 'seller') $answeredBy = Seller::find($question->answered_by_id)?->store_name;
        if ($question->answered_by_type === 'admin') $answeredBy = Admin::find($question->answered_by_id)?->name ?? 'NEXF Lifestyle';
        return ['id' => $question->id, 'question' => $question->question, 'customer_name' => $question->customer?->name, 'answer' => $question->answer, 'answered_by' => $answeredBy, 'created_at' => $question->created_at?->toDateString(), 'answered_at' => $question->answered_at?->toDateString()];
    }
}
