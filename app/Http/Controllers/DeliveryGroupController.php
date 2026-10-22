<?php
namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\DeliveryGroup;
use App\Models\Seller;
use App\Models\SellerShippingRate;
use App\Models\ShippingMethod;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeliveryGroupController extends Controller
{
    public function index(Request $request): JsonResponse { $this->admin($request); return response()->json(DeliveryGroup::query()->orderBy('id')->get()->map(fn($group) => $this->shape($group))); }
    public function publicIndex(): JsonResponse { return response()->json(DeliveryGroup::query()->orderBy('id')->get()->map(fn($group) => $this->shape($group))); }
    public function sellerGroup(Request $request): JsonResponse {
        $seller=$request->user(); abort_unless($seller instanceof Seller,403);
        $group=DeliveryGroup::query()->get()->first(fn($row)=>in_array($seller->id, $row->seller_ids ?? [], true));
        return response()->json($group ? $this->shape($group) : null);
    }
    public function store(Request $request): JsonResponse { $this->admin($request); return response()->json($this->save($request),201); }
    public function update(Request $request, DeliveryGroup $deliveryGroup): JsonResponse { $this->admin($request); return response()->json($this->save($request,$deliveryGroup)); }
    public function destroy(Request $request, DeliveryGroup $deliveryGroup): JsonResponse { $this->admin($request); $deliveryGroup->delete(); return response()->json(['message'=>'Shared delivery group deleted.']); }

    private function save(Request $request, ?DeliveryGroup $group=null): array {
        $data=$request->validate(['name'=>['required','string','max:255'],'seller_ids'=>['present','array'],'seller_ids.*'=>['integer','distinct','exists:sellers,id'],'includes_house'=>['required','boolean'],'ships_from_seller_id'=>['nullable','integer','exists:sellers,id']]);
        $sellerIds=array_values(array_map('intval',$data['seller_ids']));$hasHouse=(bool)$data['includes_house'];$memberCount=count($sellerIds)+($hasHouse?1:0);
        if($memberCount<2)throw ValidationException::withMessages(['seller_ids'=>['A shared delivery group needs at least two stores.']]);
        $main=$data['ships_from_seller_id']===null?null:(int)$data['ships_from_seller_id'];
        if($main===null&&!$hasHouse)throw ValidationException::withMessages(['ships_from_seller_id'=>['The main store must belong to the group.']]);
        if($main!==null&&!in_array($main,$sellerIds,true))throw ValidationException::withMessages(['ships_from_seller_id'=>['The main store must belong to the group.']]);
        $saved=DB::transaction(function()use($data,$sellerIds,$hasHouse,$main,$group){
            $others=DeliveryGroup::query()->when($group,fn($q)=>$q->whereKeyNot($group->id))->lockForUpdate()->get();
            foreach($others as $other){if(array_intersect($sellerIds,$other->seller_ids??[])||($hasHouse&&$other->includes_house))throw ValidationException::withMessages(['seller_ids'=>['A store can belong to only one shared delivery group.']]);}
            $record=$group?DeliveryGroup::query()->lockForUpdate()->findOrFail($group->id):new DeliveryGroup();
            $record->fill(['name'=>trim($data['name']),'seller_ids'=>$sellerIds,'includes_house'=>$hasHouse,'ships_from_seller_id'=>$main])->save();return $record;
        });
        return $this->shape($saved);
    }

    public static function groupForSeller(?int $sellerId): ?DeliveryGroup {
        return DeliveryGroup::query()->get()->first(fn(DeliveryGroup $group)=>$sellerId===null?$group->includes_house:in_array($sellerId,$group->seller_ids??[],true));
    }
    public static function isLinkedSeller(Seller $seller): bool { $group=self::groupForSeller($seller->id);return $group!==null && $group->ships_from_seller_id !== $seller->id; }
    public static function options(?int $sellerId): array {
        $store=Store::primary();$custom=$sellerId===null?(bool)$store?->delivery_options_customized:(bool)Seller::find($sellerId)?->delivery_options_customized;
        $methods=ShippingMethod::query()->active()->when($custom,fn($q)=>$sellerId===null?$q->whereNull('seller_id')->where('is_store_option',true):$q->where('seller_id',$sellerId),fn($q)=>$q->whereNull('seller_id')->where('is_store_option',false))->orderBy('sort_order')->orderBy('id')->get();
        $rates=$sellerId===null?collect():SellerShippingRate::where('seller_id',$sellerId)->pluck('charge','shipping_method_id');
        return $methods->map(fn($method)=>['id'=>$method->id,'name'=>$method->name,'charge'=>(float)($rates[$method->id]??$method->charge)])->values()->all();
    }
    private function shape(DeliveryGroup $group): array {
        $ids=array_map('intval',$group->seller_ids??[]);$sellers=Seller::whereIn('id',$ids)->get()->keyBy('id');$members=[];
        if($group->includes_house)$members[]=['seller_id'=>null,'store_name'=>Store::primary()?->name??config('app.name')];
        foreach($ids as $id)if($seller=$sellers->get($id))$members[]=['seller_id'=>$id,'store_name'=>$seller->store_name];
        return ['id'=>$group->id,'name'=>$group->name,'seller_ids'=>$ids,'includes_house'=>(bool)$group->includes_house,'ships_from_seller_id'=>$group->ships_from_seller_id===null?null:(int)$group->ships_from_seller_id,'options'=>self::options($group->ships_from_seller_id),'members'=>$members];
    }
    private function admin(Request $request):void{$admin=$request->user();abort_unless($admin instanceof Admin&&in_array($admin->role,['admin','super_admin'],true),403);}
}
