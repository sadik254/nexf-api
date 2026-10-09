<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\ContentReport;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductQuestion;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContentReportController extends Controller
{
    public function index(Request $request): JsonResponse { $this->admin($request); $data=$request->validate(['status'=>['sometimes',Rule::in(['open','actioned','dismissed'])],'target_type'=>['sometimes',Rule::in(['product','review','question','answer'])],'search'=>['sometimes','string','max:100']]); $q=ContentReport::with(['reporter:id,name,email','product:id,name,slug'])->latest(); if(isset($data['status']))$q->where('status',$data['status']); if(isset($data['target_type']))$q->where('target_type',$data['target_type']); if(!empty($data['search']))$q->where(fn($x)=>$x->where('reason','like','%'.$data['search'].'%')->orWhere('note','like','%'.$data['search'].'%')); return response()->json($q->paginate(25)); }
    public function store(Request $request): JsonResponse { $customer=$request->user(); abort_unless($customer instanceof Customer,403); $data=$request->validate(['target_type'=>['required',Rule::in(['product','review','question','answer'])],'target_id'=>['required','integer'],'product_id'=>['nullable','integer','exists:products,id'],'reason'=>['required','string','max:100'],'note'=>['nullable','string','max:3000']]); return response()->json(ContentReport::create(['reporter_customer_id'=>$customer->id]+$data),201); }
    public function resolve(Request $request, ContentReport $contentReport): JsonResponse { $admin=$this->admin($request); $data=$request->validate(['status'=>['required',Rule::in(['actioned','dismissed'])],'resolution'=>['nullable','string','max:3000']]); if($data['status']==='actioned') $this->act($contentReport); $contentReport->update(['status'=>$data['status'],'resolution'=>$data['resolution']??null,'resolved_by_admin_id'=>$admin->id,'resolved_at'=>now()]); return response()->json($contentReport->fresh()->load(['reporter:id,name,email','product:id,name,slug'])); }
    private function act(ContentReport $report): void { if($report->target_type==='product') Product::whereKey($report->target_id)->update(['status'=>'unlisted']); if($report->target_type==='review') Review::whereKey($report->target_id)->update(['status'=>'rejected']); if($report->target_type==='question') ProductQuestion::whereKey($report->target_id)->delete(); if($report->target_type==='answer') ProductQuestion::whereKey($report->target_id)->update(['answer'=>null,'answered_at'=>null]); }
    private function admin(Request $request): Admin { $admin=$request->user(); abort_unless($admin instanceof Admin && $admin->role==='super_admin',403); return $admin; }
}
