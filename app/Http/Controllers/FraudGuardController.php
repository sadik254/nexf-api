<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Seller;
use App\Models\FraudGuardRule;
use App\Models\FraudGuardSetting;
use App\Models\FraudGuardBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FraudGuardController extends Controller
{
    public function state(Request $request): JsonResponse { $this->authorize($request); return $this->stateFor(null); }
    public function saveSettings(Request $request): JsonResponse { $this->authorize($request); return $this->saveSettingsFor($request, null); }
    public function addBlock(Request $request): JsonResponse { $admin=$this->admin($request); return $this->addBlockFor($request, null, 'admin', $admin->id, $admin->id); }
    public function removeBlock(Request $request, FraudGuardBlock $fraudGuardBlock): JsonResponse { $this->authorize($request); abort_unless($fraudGuardBlock->seller_id === null, 404); $fraudGuardBlock->delete(); return $this->state($request); }
    public function sellerState(Request $request): JsonResponse { return $this->stateFor($this->seller($request)); }
    public function sellerSaveSettings(Request $request): JsonResponse { return $this->saveSettingsFor($request, $this->seller($request)); }
    public function sellerAddBlock(Request $request): JsonResponse
    {
        $seller = $this->seller($request);
        $staff = $request->attributes->get('seller_staff');
        $actor = $staff ?: $seller;
        return $this->addBlockFor($request, $seller, $staff ? 'seller_staff' : 'seller', $actor->id);
    }
    public function sellerRemoveBlock(Request $request, FraudGuardBlock $fraudGuardBlock): JsonResponse
    {
        abort_if($request->attributes->has('seller_staff'), 403);
        $seller = $this->seller($request);
        abort_unless((int) $fraudGuardBlock->seller_id === $seller->id, 404);
        $fraudGuardBlock->delete();
        return $this->stateFor($seller);
    }
    public function index(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::latest()->get()); }
    public function store(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::create($this->validated($request)), 201); }
    public function update(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->update($this->validated($request, true)); return response()->json($fraudGuardRule->fresh()); }
    public function destroy(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->delete(); return response()->json(['message' => 'Fraud Guard rule deleted.']); }
    private function validated(Request $request, bool $partial = false): array { return $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'], 'rule_type' => [$partial ? 'sometimes' : 'required', Rule::in(['phone_velocity','ip_velocity','order_value','cod_block'])], 'configuration' => [$partial ? 'sometimes' : 'required', 'array'], 'configuration.threshold' => ['nullable', 'numeric', 'min:0'], 'configuration.window_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'], 'is_active' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]); }
    private function admin(Request $request): Admin { $this->authorize($request); return $request->user(); }
    private function authorize(Request $request): void { abort_unless($request->user() instanceof Admin, 403); }
    private function seller(Request $request): Seller { abort_unless($request->user() instanceof Seller, 403); return $request->user(); }
    private function settings(Request $request): array { return $request->validate(['enabled'=>['required','boolean'],'ip_block'=>['required','boolean'],'device_block'=>['required','boolean'],'phone_blacklist'=>['required','boolean'],'fake_number_detection'=>['required','boolean'],'repeat_order_block'=>['sometimes','boolean'],'repeat_order_minutes'=>['sometimes','integer','min:1','max:1440']]); }
    private function stateFor(?Seller $seller): JsonResponse
    {
        $sellerId = $seller?->id;
        $defaults = $seller ? ['enabled'=>false,'ip_block'=>false,'device_block'=>false,'phone_blacklist'=>false,'fake_number_detection'=>false,'repeat_order_block'=>false,'repeat_order_minutes'=>5] : ['enabled'=>true,'ip_block'=>true,'device_block'=>true,'phone_blacklist'=>true,'fake_number_detection'=>true,'repeat_order_block'=>false,'repeat_order_minutes'=>5];
        return response()->json(['settings' => FraudGuardSetting::query()->where('seller_id', $sellerId)->first() ?? new FraudGuardSetting($defaults + ['seller_id' => $sellerId]), 'blocks' => FraudGuardBlock::query()->where('seller_id', $sellerId)->latest()->get()]);
    }
    private function saveSettingsFor(Request $request, ?Seller $seller): JsonResponse
    {
        $sellerId = $seller?->id;
        $settings = FraudGuardSetting::query()->firstOrNew(['seller_id' => $sellerId]);
        $settings->fill($this->settings($request));
        $settings->seller_id = $sellerId;
        $settings->save();
        return $this->stateFor($seller);
    }
    private function addBlockFor(Request $request, ?Seller $seller, string $actorType, int $actorId, ?int $adminId = null): JsonResponse
    {
        $data=$request->validate(['kind'=>['required',Rule::in(['ip','device','phone'])],'value'=>['required','string','max:128'],'reason'=>['nullable','string','max:200']]);
        $value=$this->normaliseBlock($data['kind'],$data['value']);
        if (!$value) return response()->json(['message'=>'Enter a valid value for this block type.'],422);
        FraudGuardBlock::query()->firstOrCreate(['seller_id'=>$seller?->id,'kind'=>$data['kind'],'value'=>$value], ['reason'=>$data['reason']??null,'created_by_admin_id'=>$adminId,'created_by_type'=>$actorType,'created_by_id'=>$actorId]);
        return $this->stateFor($seller);
    }
    private function normaliseBlock(string $kind, string $value): ?string { $value=trim($value); if ($kind==='phone') { $value=preg_replace('/\D/','',$value); if (str_starts_with($value,'880')) $value=substr($value,2); return preg_match('/^01[3-9]\d{8}$/',$value)?$value:null; } if ($kind==='ip') return filter_var($value,FILTER_VALIDATE_IP)?strtolower($value):null; return preg_match('/^[a-f0-9]{16,64}$/i',$value)?strtolower($value):null; }
}
