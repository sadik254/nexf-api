<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Review;
use App\Models\StoreChat;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    public function index(Request $request): JsonResponse { $this->admin($request); $data=$request->validate(['status'=>['sometimes',Rule::in(['open','actioned','dismissed'])],'target_type'=>['sometimes',Rule::in(['product','review','seller_response','question','answer','chat','ticket'])],'search'=>['sometimes','string','max:100']]); $q=ContentReport::with(['reporter:id,name,email','product:id,name,slug'])->latest(); if(isset($data['status']))$q->where('status',$data['status']); if(isset($data['target_type']))$q->where('target_type',$data['target_type']); if(!empty($data['search']))$q->where(fn($x)=>$x->where('reason','like','%'.$data['search'].'%')->orWhere('note','like','%'.$data['search'].'%')); $reports=$q->paginate(25); $reports->getCollection()->transform(fn(ContentReport $report)=>$this->present($report)); return response()->json($reports); }
    public function store(Request $request): JsonResponse
    {
        $customer = $request->user();
        abort_unless($customer instanceof Customer, 403);
        $data = $request->validate(['target_type' => ['required', Rule::in(['product','review','seller_response','question','answer','chat','ticket'])], 'target_id' => ['required','integer','min:1'], 'product_id' => ['nullable','integer','exists:products,id'], 'reason' => ['required','string','max:100'], 'note' => ['nullable','string','max:3000']]);
        $target = match ($data['target_type']) {
            'product' => Product::availableForSale()->findOrFail($data['target_id']),
            'review', 'seller_response' => Review::where('status','approved')->whereHas('product', fn($q) => $q->availableForSale())->findOrFail($data['target_id']),
            'question', 'answer' => ProductQuestion::where('status','approved')->whereHas('product', fn($q) => $q->availableForSale())->findOrFail($data['target_id']),
            'chat' => StoreChat::where('customer_id',$customer->id)->findOrFail($data['target_id']),
            'ticket' => SupportTicket::where('customer_id',$customer->id)->findOrFail($data['target_id']),
        };
        if ($data['target_type'] === 'seller_response') abort_unless((bool)$target->seller_response,404);
        if ($data['target_type'] === 'answer') abort_unless((bool)$target->answer,404);
        $productId = match ($data['target_type']) { 'product' => $target->id, 'review','seller_response','question','answer' => $target->product_id, default => null };
        if (isset($data['product_id']) && (int)$data['product_id'] !== (int)$productId) throw \Illuminate\Validation\ValidationException::withMessages(['product_id' => ['The product does not match the reported content.']]);
        $data['product_id'] = $productId;
        return response()->json(ContentReport::create(['reporter_customer_id'=>$customer->id]+$data),201);
    }
    public function resolve(Request $request, ContentReport $contentReport): JsonResponse { $admin=$this->admin($request); $data=$request->validate(['status'=>['required',Rule::in(['actioned','dismissed'])],'resolution'=>['nullable','string','max:3000']]); if($data['status']==='actioned') $this->act($contentReport); ContentReport::query()->where('target_type',$contentReport->target_type)->where('target_id',$contentReport->target_id)->where('status','open')->update(['status'=>$data['status'],'resolution'=>$data['resolution']??null,'resolved_by_admin_id'=>$admin->id,'resolved_at'=>now()]); return response()->json($this->present($contentReport->fresh()->load(['reporter:id,name,email','product:id,name,slug']))); }
    private function act(ContentReport $report): void { if($report->target_type==='product') Product::whereKey($report->target_id)->update(['status'=>'unlisted']); if($report->target_type==='review') Review::whereKey($report->target_id)->update(['status'=>'rejected']); if($report->target_type==='seller_response') Review::whereKey($report->target_id)->update(['seller_response'=>null,'seller_responded_at'=>null]); if($report->target_type==='question') ProductQuestion::whereKey($report->target_id)->delete(); if($report->target_type==='answer') ProductQuestion::whereKey($report->target_id)->update(['answer'=>null,'answered_at'=>null,'answered_by_type'=>null,'answered_by_id'=>null]); if($report->target_type==='chat') StoreChat::whereKey($report->target_id)->update(['store_read_at'=>now()]); if($report->target_type==='ticket') SupportTicket::whereKey($report->target_id)->update(['status'=>'resolved','resolved_at'=>now()]); }
    private function present(ContentReport $report): ContentReport {
        $target = match ($report->target_type) {
            'product' => Product::find($report->target_id),
            'review', 'seller_response' => Review::with(['customer:id,name','product:id,name,slug'])->find($report->target_id),
            'question', 'answer' => ProductQuestion::with(['customer:id,name','product:id,name,slug'])->find($report->target_id),
            'chat' => StoreChat::with(['customer:id,name','seller:id,store_name'])->find($report->target_id),
            'ticket' => SupportTicket::with(['customer:id,name','seller:id,store_name'])->find($report->target_id),
        };
        $report->setAttribute('target_summary', match ($report->target_type) {
            'product' => $target ? ['label'=>$target->name, 'excerpt'=>$target->name, 'author'=>$target->seller?->store_name, 'href'=>'/product/'.$target->slug] : null,
            'review' => $target ? ['label'=>'Review on '.$target->product?->name, 'excerpt'=>$target->comment, 'author'=>$target->customer?->name, 'href'=>'/product/'.$target->product?->slug.'#reviews'] : null,
            'seller_response' => $target ? ['label'=>'Seller response on '.$target->product?->name, 'excerpt'=>$target->seller_response, 'author'=>$target->product?->seller?->store_name, 'href'=>'/product/'.$target->product?->slug.'#reviews'] : null,
            'question' => $target ? ['label'=>'Question on '.$target->product?->name, 'excerpt'=>$target->question, 'author'=>$target->customer?->name, 'href'=>'/product/'.$target->product?->slug.'#questions'] : null,
            'answer' => $target ? ['label'=>'Answer on '.$target->product?->name, 'excerpt'=>$target->answer, 'author'=>null, 'href'=>'/product/'.$target->product?->slug.'#questions'] : null,
            'chat' => $target ? ['label'=>($target->customer?->name ?? 'Customer').' ↔ '.($target->seller?->store_name ?? 'Store'), 'excerpt'=>'Reported conversation', 'author'=>$target->customer?->name, 'href'=>'/dashboard/chats?c='.$target->id] : null,
            'ticket' => $target ? ['label'=>$target->subject, 'excerpt'=>'Support ticket', 'author'=>$target->customer?->name, 'href'=>'/dashboard/support?ticket='.$target->id] : null,
        });
        return $report;
    }
    private function admin(Request $request): Admin { $admin=$request->user(); abort_unless($admin instanceof Admin && $admin->role==='super_admin',403); return $admin; }
}
