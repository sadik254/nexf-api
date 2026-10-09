<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\FraudGuardRule;
use App\Models\FraudGuardSetting;
use App\Models\FraudGuardBlock;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FraudGuardController extends Controller
{
    public function state(Request $request): JsonResponse { $this->authorize($request); return response()->json(['settings' => FraudGuardSetting::first() ?? new FraudGuardSetting(['enabled'=>true,'ip_block'=>true,'device_block'=>true,'phone_blacklist'=>true,'fake_number_detection'=>true]), 'blocks' => FraudGuardBlock::latest()->get()]); }
    public function saveSettings(Request $request): JsonResponse { $this->authorize($request); $settings = FraudGuardSetting::firstOrNew(); $settings->fill($request->validate(['enabled'=>['required','boolean'],'ip_block'=>['required','boolean'],'device_block'=>['required','boolean'],'phone_blacklist'=>['required','boolean'],'fake_number_detection'=>['required','boolean']])); $settings->save(); return $this->state($request); }
    public function addBlock(Request $request): JsonResponse { $admin=$this->admin($request); $data=$request->validate(['kind'=>['required',Rule::in(['ip','device','phone'])],'value'=>['required','string','max:128'],'reason'=>['nullable','string','max:200']]); $value=$this->normaliseBlock($data['kind'],$data['value']); if (!$value) return response()->json(['message'=>'Enter a valid value for this block type.'],422); FraudGuardBlock::firstOrCreate(['kind'=>$data['kind'],'value'=>$value],['reason'=>$data['reason']??null,'created_by_admin_id'=>$admin->id]); return $this->state($request); }
    public function removeBlock(Request $request, FraudGuardBlock $fraudGuardBlock): JsonResponse { $this->authorize($request); $fraudGuardBlock->delete(); return $this->state($request); }
    public function index(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::latest()->get()); }
    public function store(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::create($this->validated($request)), 201); }
    public function update(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->update($this->validated($request, true)); return response()->json($fraudGuardRule->fresh()); }
    public function destroy(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->delete(); return response()->json(['message' => 'Fraud Guard rule deleted.']); }
    private function validated(Request $request, bool $partial = false): array { return $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'], 'rule_type' => [$partial ? 'sometimes' : 'required', Rule::in(['phone_velocity','ip_velocity','order_value','cod_block'])], 'configuration' => [$partial ? 'sometimes' : 'required', 'array'], 'configuration.threshold' => ['nullable', 'numeric', 'min:0'], 'configuration.window_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'], 'is_active' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]); }
    private function admin(Request $request): Admin { $this->authorize($request); return $request->user(); }
    private function authorize(Request $request): void { abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403); }
    private function normaliseBlock(string $kind, string $value): ?string { $value=trim($value); if ($kind==='phone') { $value=preg_replace('/\D/','',$value); if (str_starts_with($value,'880')) $value=substr($value,2); return preg_match('/^01[3-9]\d{8}$/',$value)?$value:null; } if ($kind==='ip') return filter_var($value,FILTER_VALIDATE_IP)?strtolower($value):null; return preg_match('/^[a-f0-9]{16,64}$/i',$value)?strtolower($value):null; }
}
