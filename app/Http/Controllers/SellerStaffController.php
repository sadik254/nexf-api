<?php
namespace App\Http\Controllers;
use App\Models\Seller;use App\Models\SellerStaff;use App\Models\Admin;use Illuminate\Http\Request;use Illuminate\Http\JsonResponse;use Illuminate\Validation\Rule;
class SellerStaffController extends Controller{
 private function owner(Request $r):Seller{abort_unless(!$r->attributes->has('seller_staff') && $r->user() instanceof Seller,403);return $r->user();}
 private function data(Request $r,bool $new=false):array{return $r->validate(['name'=>[$new?'required':'sometimes','string','max:255'],'email'=>[$new?'required':'sometimes','email','max:255',Rule::unique('seller_staff')->ignore($r->route('staff'))],'phone'=>['sometimes','nullable','string','max:32'],'status'=>['sometimes',Rule::in(['active','suspended'])]]);}
 public function index(Request $r):JsonResponse{return response()->json($this->owner($r)->staff()->latest()->get());}
 public function store(Request $r):JsonResponse{$seller=$this->owner($r);$d=$this->data($r,true);$d['status']=($d['status']??'active')==='suspended'?'suspended':'invited';return response()->json($seller->staff()->create($d),201);}
 public function update(Request $r,SellerStaff $staff):JsonResponse{abort_unless($staff->seller_id===$this->owner($r)->id,404);$d=$this->data($r);if(($d['status']??null)==='active'&&$staff->status==='invited')unset($d['status']);$staff->update($d);if(($d['status']??null)==='suspended')$staff->tokens()->delete();return response()->json($staff->fresh());}
 public function destroy(Request $r,SellerStaff $staff):JsonResponse{abort_unless($staff->seller_id===$this->owner($r)->id,404);$staff->tokens()->delete();$staff->delete();return response()->json(['message'=>'Staff removed.']);}
 public function adminIndex(Request $r):JsonResponse{abort_unless($r->user() instanceof Admin,403);return response()->json(SellerStaff::with('seller:id,store_name,store_slug')->when($r->query('seller_id'),fn($q,$id)=>$q->where('seller_id',$id))->latest()->paginate(25));}
 public function adminStore(Request $r,Seller $seller):JsonResponse{abort_unless($r->user() instanceof Admin,403);$d=$this->data($r,true);$d['status']=($d['status']??'active')==='suspended'?'suspended':'invited';return response()->json($seller->staff()->create($d)->load('seller:id,store_name,store_slug'),201);}
 public function adminUpdate(Request $r,SellerStaff $staff):JsonResponse{abort_unless($r->user() instanceof Admin,403);$d=$this->data($r);$d+= $r->validate(['seller_id'=>['sometimes','integer','exists:sellers,id']]);$staff->update($d);if(($d['status']??null)==='suspended')$staff->tokens()->delete();return response()->json($staff->fresh()->load('seller:id,store_name,store_slug'));}
 public function adminDestroy(Request $r,SellerStaff $staff):JsonResponse{abort_unless($r->user() instanceof Admin,403);$staff->tokens()->delete();$staff->delete();return response()->json(['message'=>'Staff removed.']);}
}
