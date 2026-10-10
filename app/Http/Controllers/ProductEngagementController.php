<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Customer;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\ReviewLike;
use App\Models\MediaAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
            ->when($request->filled('product_id'),fn($q)=>$q->where('product_id',(int)$request->query('product_id')))
            ->with(['product:id,name,slug,thumbnail,gallery,seller_id,owner_unassigned','product.seller:id,store_name','orderItem:id,variation_attributes,seller_id,order_id','orderItem.seller:id,store_name','orderItem.order:id','orderItem.order.storeGroups:id,order_id,seller_id,store_name'])->withCount('likes')
            ->latest()->paginate($perPage)->through(function ($review) {
                $item = $review->orderItem;
                $group = $item?->order?->storeGroups?->first(fn($group) => $group->seller_id === $item->seller_id);
                $name = $group?->store_name ?? ($item ? ($item->seller_id === null ? (\App\Models\Store::primary()?->name ?? config('app.name')) : $item->seller?->store_name) : null);
                if ($name === null && !$review->product?->owner_unassigned) $name = $review->product?->seller_id === null ? (\App\Models\Store::primary()?->name ?? config('app.name')) : $review->product?->seller?->store_name;
                $review->product?->setAttribute('store_name', $name);
                return $review;
            }));
    }

    public function reviewableItems(Request $request): JsonResponse
    {
        $customer = $request->user();
        $perPage = max(1, min((int) $request->query('per_page', 20), 100));
        return response()->json(OrderItem::query()
            ->where('fulfillment_status', 'delivered')
            ->when($request->filled('product_id'),fn($q)=>$q->where('product_id',(int)$request->query('product_id')))
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customer->id))
            ->whereDoesntHave('review')->with('seller:id,store_name')
            ->latest()->paginate($perPage)->through(function($item){$item->setAttribute('seller_store_name',$item->seller_id === null ? (\App\Models\Store::primary()?->name ?? config('app.name')) : $item->seller?->store_name);return $item;}));
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
        return $this->moderationReviews($request, null);
    }

    public function moderateReview(Review $review, Request $request): JsonResponse
    {
        abort_if($review->product()->whereNotNull('seller_id')->exists(), 403, 'Seller product reviews must be moderated by the product owner.');

        return $this->applyReviewModeration($review, $request);
    }

    public function reviewsForSeller(Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();

        return $this->moderationReviews($request, $seller->id);
    }

    public function questionsForAdmin(Request $request): JsonResponse
    {
        return $this->moderationQuestions($request, null);
    }

    public function questionsForSeller(Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();

        return $this->moderationQuestions($request, $seller->id);
    }

    public function moderateReviewForSeller(Review $review, Request $request): JsonResponse
    {
        /** @var Seller $seller */
        $seller = $request->user();
        abort_unless((int) $review->product()->value('seller_id') === (int) $seller->id, 403, 'You can only moderate reviews for your own products.');

        return $this->applyReviewModeration($review, $request);
    }

    public function questions(Product $product, Request $request): JsonResponse
    {
        abort_unless(Product::availableForSale()->whereKey($product->id)->exists(), 404);
        abort_unless(in_array($product->status, ['active', 'unlisted'], true), 404);
        $perPage = max(1, min((int) $request->query('per_page', 10), 50));
        $questions = $product->questions()->where('status', '!=', 'rejected')->with('customer:id,name')->latest()->paginate($perPage);
        $questions->getCollection()->transform(fn (ProductQuestion $question) => $this->questionPayload($question));
        return response()->json($questions);
    }

    public function ask(Product $product, Request $request): JsonResponse
    {
        abort_unless(Product::availableForSale()->whereKey($product->id)->exists(), 404);
        abort_unless(in_array($product->status, ['active', 'unlisted'], true), 404);
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
        abort_unless(in_array($product->status, ['active', 'unlisted'], true), 404);
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['order_item_id' => ['sometimes', 'integer', 'exists:order_items,id'], 'rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:3000'], 'images' => ['sometimes','array','max:6'], 'images.*' => ['url:http,https','max:2048'], 'videos' => ['sometimes','array','max:2'], 'videos.*' => ['url:http,https','max:2048']]);
        $item = OrderItem::query()
            ->when(isset($data['order_item_id']), fn ($query) => $query->whereKey($data['order_item_id']))
            ->where('product_id', $product->id)
            ->where('fulfillment_status', 'delivered')
            ->whereHas('order', fn ($query) => $query->where('customer_id', $customer->id))
            ->latest('id')
            ->first();
        if (!$item) return response()->json(['message' => 'Only customers with a delivered purchase can review this product.'], 422);
        foreach (['images' => 'image/', 'videos' => 'video/'] as $field => $mime) {
            foreach ($data[$field] ?? [] as $url) {
                if (!MediaAsset::where('owner_type', 'customer')->where('owner_id', $customer->id)->where('url', $url)->where('mime_type', 'like', $mime.'%')->exists()) throw ValidationException::withMessages([$field => ['Select media uploaded by your own account.']]);
            }
        }
        $review = Review::updateOrCreate(['customer_id' => $customer->id, 'order_item_id' => $item->id], ['product_id' => $product->id, 'rating' => $data['rating'], 'comment' => $data['comment'] ?? null, 'images' => $data['images'] ?? [], 'videos' => $data['videos'] ?? [], 'status' => 'pending', 'rejection_note' => null, 'moderated_at' => null]);
        return response()->json(['message' => 'Review submitted for approval.', 'review' => $review], $review->wasRecentlyCreated ? 201 : 200);
    }

    private function questionPayload(ProductQuestion $question): array
    {
        $answeredBy = null;
        if ($question->answered_by_type === 'seller') $answeredBy = Seller::find($question->answered_by_id)?->store_name;
        if ($question->answered_by_type === 'admin') $answeredBy = Admin::find($question->answered_by_id)?->name ?? 'NEXF Lifestyle';
        return ['id' => $question->id, 'question' => $question->question, 'customer_name' => $question->customer?->name, 'answer' => $question->answer, 'answered_by' => $answeredBy, 'created_at' => $question->created_at?->toDateString(), 'answered_at' => $question->answered_at?->toDateString(), 'status' => $question->status, 'rejection_note' => $question->rejection_note, 'moderated_at' => $question->moderated_at?->toDateString()];
    }

    private function moderationReviews(Request $request, ?int $sellerId): JsonResponse
    {
        $status = $request->validate(['status' => ['sometimes', 'in:pending,approved,rejected,all']])['status'] ?? 'pending';
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $reviews = Review::query()->withCount('likes')
            ->with(['customer:id,name,profile_picture', 'product:id,name,slug,thumbnail,seller_id', 'product.seller:id,store_name,store_slug', 'orderItem:id,variation_attributes'])
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->whereHas('product', fn ($query) => $sellerId === null
                ? $query->whereNull('seller_id')
                : $query->where('seller_id', $sellerId))
            ->latest()
            ->paginate($perPage);

        return response()->json($reviews);
    }

    private function moderationQuestions(Request $request, ?int $sellerId): JsonResponse
    {
        $state = $request->validate(['state' => ['sometimes', 'in:unanswered,answered,rejected,all']])['state'] ?? 'unanswered';
        $perPage = max(1, min((int) $request->query('per_page', 25), 100));
        $questions = ProductQuestion::query()
            ->with(['customer:id,name', 'product:id,name,slug,thumbnail,gallery,seller_id'])
            ->whereHas('product', fn ($query) => $sellerId === null
                ? $query->whereNull('seller_id')
                : $query->where('seller_id', $sellerId))
            ->when($state === 'unanswered', fn ($query) => $query->whereNull('answer')->where('status', '!=', 'rejected'))
            ->when($state === 'answered', fn ($query) => $query->whereNotNull('answer')->where('status', '!=', 'rejected'))
            ->when($state === 'rejected', fn ($query) => $query->where('status', 'rejected'))
            ->latest()
            ->paginate($perPage);

        $questions->getCollection()->transform(fn (ProductQuestion $question) => [
            ...$this->questionPayload($question),
            'product' => $question->product,
        ]);

        return response()->json($questions);
    }

    private function authorizeQuestionOwner(Request $request, ProductQuestion $question): void
    {
        $actor = $request->user();
        $sellerId = $question->product()->value('seller_id');
        abort_unless(($actor instanceof Admin && $sellerId === null) ||
            ($actor instanceof Seller && (int) $sellerId === (int) $actor->id), 403);
    }

    public function editQuestion(Request $request, ProductQuestion $question): JsonResponse
    {
        $this->authorizeQuestionOwner($request, $question);
        $data = $request->validate(['question' => ['required', 'string', 'min:5', 'max:1000'], 'answer' => ['nullable', 'string', 'max:2000']]);
        $answer = trim($data['answer'] ?? '');
        $actor = $request->user();
        $patch = ['question' => $data['question'], 'answer' => $answer ?: null];
        if (($question->answer ?? '') !== $answer) $patch += [
            'answered_by_type' => $answer ? ($actor instanceof Seller ? 'seller' : 'admin') : null,
            'answered_by_id' => $answer ? $actor->id : null, 'answered_at' => $answer ? now() : null];
        $question->update($patch);
        return response()->json(['message' => 'Question updated.', 'question' => $this->questionPayload($question->fresh('customer:id,name'))]);
    }

    public function moderateQuestion(Request $request, ProductQuestion $question): JsonResponse
    {
        abort_unless($request->user() instanceof Admin, 403);
        $this->authorizeQuestionOwner($request, $question);
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'rejection_note' => ['nullable', 'string', 'max:2000']]);
        $question->update(['status' => $data['status'], 'rejection_note' => $data['status'] === 'rejected' ? ($data['rejection_note'] ?? null) : null, 'moderated_at' => now()]);
        return response()->json(['message' => 'Question moderated.', 'question' => $this->questionPayload($question->fresh('customer:id,name'))]);
    }

    public function deleteQuestion(Request $request, ProductQuestion $question): JsonResponse
    {
        $this->authorizeQuestionOwner($request, $question);
        $question->delete();
        return response()->json(['message' => 'Question deleted.']);
    }

    public function editReview(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user() instanceof Admin && $review->product()->value('seller_id') === null, 403);
        $data=$request->validate(['comment'=>['nullable','string','max:3000'],'images'=>['present','array','max:6'],'images.*'=>['url:http,https'],'videos'=>['present','array','max:2'],'videos.*'=>['url:http,https'],'seller_response'=>['nullable','string','max:2000'],'rating'=>['prohibited']]);
        $updated=DB::transaction(function()use($review,$data){$locked=Review::whereKey($review->id)->lockForUpdate()->firstOrFail();foreach(['images','videos'] as $field){if(array_diff($data[$field],$locked->$field??[]))throw ValidationException::withMessages([$field=>['Moderators may only remove existing review media.']]);}$locked->update($data);return $locked;});
        return response()->json(['message'=>'Review updated.','review'=>$updated]);
    }
    public function deleteReview(Request $request, Review $review): JsonResponse
    {
        abort_unless($request->user() instanceof Admin && $review->product()->value('seller_id') === null,403);
        $review->delete();return response()->json(['message'=>'Review deleted.']);
    }
    public function respondToReview(Request $request, Review $review): JsonResponse
    {
        $seller=$request->user();abort_unless($seller instanceof Seller && (int)$review->product()->value('seller_id')===(int)$seller->id,403);
        abort_unless($review->status==='approved',422,'Only published reviews can receive a public response.');
        $data=$request->validate(['seller_response'=>['nullable','string','max:2000']]);
        $text=trim($data['seller_response']??'');$review->update(['seller_response'=>$text?:null,'seller_responded_at'=>$text?now():null]);
        return response()->json(['message'=>'Response saved.','review'=>$review->fresh()]);
    }
    public function likeReview(Request $request, Review $review): JsonResponse
    {
        $customer=$request->user();abort_unless($customer instanceof Customer,403);abort_unless($review->status==='approved',404);
        $data=$request->validate(['liked'=>['required','boolean'],'target_type'=>['sometimes','in:review,seller_response']]);$target=$data['target_type']??'review';if($target==='seller_response')abort_unless((bool)$review->seller_response,404);
        DB::transaction(function()use($customer,$review,$data,$target){Review::whereKey($review->id)->lockForUpdate()->firstOrFail();if($data['liked'])ReviewLike::firstOrCreate(['review_id'=>$review->id,'customer_id'=>$customer->id,'target_type'=>$target]);else ReviewLike::where('review_id',$review->id)->where('customer_id',$customer->id)->where('target_type',$target)->delete();});
        return response()->json(['liked'=>$data['liked'],'likes'=>ReviewLike::where('review_id',$review->id)->where('target_type',$target)->count()]);
    }
    public function likedReviews(Request $request): JsonResponse
    {
        $customer=$request->user();abort_unless($customer instanceof Customer,403);
        $data=$request->validate(['target_type'=>['sometimes','in:review,seller_response']]);return response()->json(ReviewLike::where('customer_id',$customer->id)->where('target_type',$data['target_type']??'review')->pluck('review_id'));
    }

    private function applyReviewModeration(Review $review, Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:approved,rejected'], 'rejection_note' => ['nullable','string','max:2000']]);
        $review->update(['status' => $data['status'], 'rejection_note' => $data['status']==='rejected'?($data['rejection_note']??null):null, 'moderated_at'=>now()]);

        return response()->json([
            'message' => "Review {$data['status']} successfully.",
            'review' => $review->fresh(['customer:id,name,profile_picture', 'product:id,name,slug,thumbnail,seller_id', 'product.seller:id,store_name,store_slug', 'orderItem:id,variation_attributes']),
        ]);
    }
}
