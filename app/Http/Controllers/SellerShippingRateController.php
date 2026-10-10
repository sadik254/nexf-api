<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\SellerShippingRate;
use App\Models\ShippingMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerShippingRateController extends Controller
{
    public function sellerIndex(Request $request): JsonResponse { return $this->index($request->user()); }
    public function sellerUpdate(Request $request): JsonResponse { return $this->update($request->user(), $request); }

    public function adminIndex(Seller $seller, Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return $this->index($seller);
    }

    public function adminUpdate(Seller $seller, Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        return $this->update($seller, $request);
    }

    private function index(Seller $seller): JsonResponse
    {
        $rates = SellerShippingRate::where('seller_id', $seller->id)->pluck('charge', 'shipping_method_id');
        return response()->json(ShippingMethod::query()->active()->when($seller->delivery_options_customized, fn($q)=>$q->where('seller_id',$seller->id), fn($q)=>$q->whereNull('seller_id')->where('is_store_option',false))->orderBy('sort_order')->orderBy('id')->get()->map(fn ($method) => [
            'shipping_method_id' => $method->id,
            'code' => $method->code,
            'name' => $method->name,
            'currency' => $method->currency,
            'is_active' => $method->is_active,
            'default_charge' => $method->charge,
            'charge' => $rates[$method->id] ?? $method->charge,
            'has_override' => $method->seller_id !== null || $rates->has($method->id),
            'is_custom' => $method->seller_id !== null,
        ]));
    }

    private function update(Seller $seller, Request $request): JsonResponse
    {
        if ($request->exists('options')) return $this->saveOptions($seller,$request);
        $data = $request->validate([
            'rates' => ['required', 'array', 'min:1'],
            'rates.*.shipping_method_id' => ['required', 'integer', 'distinct', 'exists:shipping_methods,id'],
            'rates.*.charge' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);
        DB::transaction(function () use ($seller, $data) {
            foreach ($data['rates'] as $rate) {
                abort_unless(ShippingMethod::whereKey($rate['shipping_method_id'])->where(fn($q)=>$q->whereNull('seller_id')->where('is_store_option',false)->orWhere('seller_id',$seller->id))->exists(),403);
                SellerShippingRate::updateOrCreate(
                    ['seller_id' => $seller->id, 'shipping_method_id' => $rate['shipping_method_id']],
                    ['charge' => $rate['charge']],
                );
            }
        });
        return $this->index($seller);
    }

    private function saveOptions(Seller $seller, Request $request): JsonResponse
    {
        $data=$request->validate(['options'=>['required','array','min:1','max:30'],'options.*.shipping_method_id'=>['nullable','integer','distinct','exists:shipping_methods,id'],'options.*.name'=>['required','string','max:255'],'options.*.charge'=>['required','numeric','min:0','max:9999999999.99']]);
        $names=array_map(fn($option)=>mb_strtolower(trim($option['name'])),$data['options']);
        if(in_array('', $names,true)||count(array_unique($names))!==count($names)) throw \Illuminate\Validation\ValidationException::withMessages(['options'=>['Each delivery option needs a unique name.']]);
        DB::transaction(function()use($seller,$data){
            $locked=Seller::whereKey($seller->id)->lockForUpdate()->firstOrFail();$keep=[];
            foreach($data['options'] as $position=>$option){
                $method=isset($option['shipping_method_id'])?ShippingMethod::whereKey($option['shipping_method_id'])->lockForUpdate()->firstOrFail():null;
                if($method?->is_store_option&&$method->seller_id===null)abort(403);
                if($method?->seller_id!==null)abort_unless((int)$method->seller_id===(int)$seller->id,403);
                if(!$method||$method->seller_id===null)$method=new ShippingMethod(['seller_id'=>$seller->id,'is_store_option'=>true,'code'=>'store_'.$seller->id.'_'.\Illuminate\Support\Str::uuid(),'currency'=>'BDT']);
                $method->fill(['name'=>trim($option['name']),'charge'=>$option['charge'],'sort_order'=>$position,'is_active'=>true])->save();$keep[]=$method->id;
            }
            ShippingMethod::where('seller_id',$seller->id)->whereNotIn('id',$keep)->update(['is_active'=>false]);
            $locked->forceFill(['delivery_options_customized'=>true])->save();
        });
        return $this->index($seller->fresh());
    }

    public function platformIndex(Request $request): JsonResponse
    {
        abort_unless($request->user() instanceof Admin,403);return $this->platformRates();
    }
    private function platformRates(): JsonResponse
    {
        $store=\App\Models\Store::primary();$custom=(bool)$store?->delivery_options_customized;
        $methods=ShippingMethod::whereNull('seller_id')->where('is_store_option',$custom)->active()->orderBy('sort_order')->orderBy('id')->get();
        return response()->json(['store'=>['name'=>$store?->name??config('app.name')],'rates'=>$methods->map(fn($method)=>['shipping_method_id'=>$method->id,'code'=>$method->code,'name'=>$method->name,'currency'=>$method->currency,'is_active'=>$method->is_active,'default_charge'=>$method->charge,'charge'=>$method->charge,'has_override'=>$custom,'is_custom'=>$custom])]);
    }
    public function platformUpdate(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);
        $data=$request->validate(['options'=>['required','array','min:1','max:30'],'options.*.shipping_method_id'=>['nullable','integer','distinct','exists:shipping_methods,id'],'options.*.name'=>['required','string','max:255'],'options.*.charge'=>['required','numeric','min:0','max:9999999999.99']]);
        $names=array_map(fn($option)=>mb_strtolower(trim($option['name'])),$data['options']);if(in_array('',$names,true)||count(array_unique($names))!==count($names))throw \Illuminate\Validation\ValidationException::withMessages(['options'=>['Each delivery option needs a unique name.']]);
        DB::transaction(function()use($data){
            $store=\App\Models\Store::firstOrCreate(['setup_key'=>\App\Models\Store::PRIMARY_KEY],['name'=>config('app.name')]);$store=\App\Models\Store::whereKey($store->id)->lockForUpdate()->firstOrFail();$keep=[];
            foreach($data['options'] as $position=>$option){
                $method=isset($option['shipping_method_id'])?ShippingMethod::whereKey($option['shipping_method_id'])->lockForUpdate()->firstOrFail():null;
                if($method)abort_unless($method->seller_id===null,403);
                if(!$method||!$method->is_store_option)$method=new ShippingMethod(['seller_id'=>null,'is_store_option'=>true,'code'=>'platform_'.\Illuminate\Support\Str::uuid(),'currency'=>'BDT']);
                $method->fill(['name'=>trim($option['name']),'charge'=>$option['charge'],'sort_order'=>$position,'is_active'=>true])->save();$keep[]=$method->id;
            }
            ShippingMethod::whereNull('seller_id')->where('is_store_option',true)->whereNotIn('id',$keep)->update(['is_active'=>false]);
            $store->forceFill(['delivery_options_customized'=>true])->save();
        });return $this->platformRates();
    }

    private function authorizeAdmin(Request $request): void
    {
        $admin = $request->user();
        abort_unless($admin instanceof Admin && $admin->role === 'super_admin', 403);
    }
}
