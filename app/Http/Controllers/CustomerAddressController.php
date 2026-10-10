<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class CustomerAddressController extends Controller {
 private function rows(Request $request) {return CustomerAddress::where('customer_id',$request->user()->id);}
 private function transform(CustomerAddress $address): array {return ['id'=>(string)$address->id,'label'=>$address->label,'recipient'=>$address->recipient,'phone'=>$address->phone,'line'=>$address->line,'area'=>$address->area,'district'=>$address->district,'isDefault'=>$address->is_default];}
 public function index(Request $request): JsonResponse {return response()->json($this->rows($request)->orderByDesc('is_default')->orderBy('id')->get()->map(fn($a)=>$this->transform($a)));}
 public function save(Request $request, ?int $address=null): JsonResponse {
  foreach(['label','recipient','phone','line','area','district'] as $field) if($request->has($field)) $request->merge([$field=>trim((string)$request->input($field))]);
  $data=$request->validate(['label'=>['required','string','max:100'],'recipient'=>['required','string','max:150'],'phone'=>['required','regex:/^01\d{9}$/'],'line'=>['required','string','max:2000'],'area'=>['required','string','max:150'],'district'=>['required','string','max:100'],'isDefault'=>['sometimes','boolean']]);
  $saved=DB::transaction(function() use($request,$address,$data) {
   Customer::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
   $record=$address ? $this->rows($request)->whereKey($address)->firstOrFail() : new CustomerAddress(['customer_id'=>$request->user()->id]);
   $default=($data['isDefault']??false)||$record->is_default||!$this->rows($request)->exists();
   if($default) $this->rows($request)->update(['is_default'=>false]);
   unset($data['isDefault']);$record->fill($data+['is_default'=>$default])->save();return $record;
  });return response()->json($this->transform($saved),$address?200:201);
 }
 public function makeDefault(Request $request,int $address): JsonResponse {
  $saved=DB::transaction(function()use($request,$address){
   Customer::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
   $record=$this->rows($request)->whereKey($address)->firstOrFail();
   $this->rows($request)->update(['is_default'=>false]);$record->update(['is_default'=>true]);return $record;
  });return response()->json($this->transform($saved));
 }
 public function destroy(Request $request,int $address): JsonResponse {
  DB::transaction(function()use($request,$address){
   Customer::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
   $record=$this->rows($request)->whereKey($address)->firstOrFail();
   if($record->is_default)throw ValidationException::withMessages(['address'=>'Choose another default address before deleting this one.']);
   $record->delete();
  });return response()->json(['message'=>'Address deleted.']);
 }
}
