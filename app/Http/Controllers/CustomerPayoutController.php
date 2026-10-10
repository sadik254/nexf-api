<?php
namespace App\Http\Controllers;
use App\Models\Customer;
use App\Models\CustomerPayoutAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
class CustomerPayoutController extends Controller {
 private function rows(Request $r){return CustomerPayoutAccount::where('customer_id',$r->user()->id);}
 private function transform($a):array{return ['id'=>(string)$a->id,'provider'=>$a->provider,'account'=>$a->account,'holder'=>$a->holder,'bank'=>$a->bank,'branch'=>$a->branch,'isSelected'=>$a->is_selected];}
 public function index(Request $r):JsonResponse{return response()->json($this->rows($r)->orderBy('id')->get()->map(fn($a)=>$this->transform($a)));}
 public function save(Request $r,?int $payout=null):JsonResponse{
  foreach(['account','holder','bank','branch'] as $key)if($r->has($key))$r->merge([$key=>trim((string)$r->input($key))]);
  $data=$r->validate(['provider'=>['required','in:bkash,nagad,rocket,bank'],'account'=>['required','string','max:100',$r->input('provider')==='bank'?'regex:/^\d{6,}$/':'regex:/^01\d{9}$/'],'holder'=>['required_if:provider,bank','nullable','string','max:150'],'bank'=>['required_if:provider,bank','nullable','string','max:150'],'branch'=>['required_if:provider,bank','nullable','string','max:150']]);
  $a=DB::transaction(function()use($r,$payout,$data){Customer::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();$a=$payout?$this->rows($r)->whereKey($payout)->firstOrFail():new CustomerPayoutAccount(['customer_id'=>$r->user()->id]);if(!$payout){$this->rows($r)->update(['is_selected'=>false]);$a->is_selected=true;}$a->fill($data)->save();return $a;});return response()->json($this->transform($a),$payout?200:201);
 }
 public function select(Request $r,int $payout):JsonResponse{$a=DB::transaction(function()use($r,$payout){Customer::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();$a=$this->rows($r)->whereKey($payout)->firstOrFail();$this->rows($r)->update(['is_selected'=>false]);$a->update(['is_selected'=>true]);return $a;});return response()->json($this->transform($a));}
 public function destroy(Request $r,int $payout):JsonResponse{DB::transaction(function()use($r,$payout){Customer::whereKey($r->user()->id)->lockForUpdate()->firstOrFail();$a=$this->rows($r)->whereKey($payout)->firstOrFail();$selected=$a->is_selected;$a->delete();if($selected)$this->rows($r)->orderBy('id')->first()?->update(['is_selected'=>true]);});return response()->json(['message'=>'Payout method deleted.']);}
}
