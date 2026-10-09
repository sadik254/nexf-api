<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\FraudGuardRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FraudGuardController extends Controller
{
    public function index(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::latest()->get()); }
    public function store(Request $request): JsonResponse { $this->authorize($request); return response()->json(FraudGuardRule::create($this->validated($request)), 201); }
    public function update(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->update($this->validated($request, true)); return response()->json($fraudGuardRule->fresh()); }
    public function destroy(Request $request, FraudGuardRule $fraudGuardRule): JsonResponse { $this->authorize($request); $fraudGuardRule->delete(); return response()->json(['message' => 'Fraud Guard rule deleted.']); }
    private function validated(Request $request, bool $partial = false): array { return $request->validate(['name' => [$partial ? 'sometimes' : 'required', 'string', 'max:160'], 'rule_type' => [$partial ? 'sometimes' : 'required', Rule::in(['phone_velocity','ip_velocity','order_value','cod_block'])], 'configuration' => [$partial ? 'sometimes' : 'required', 'array'], 'configuration.threshold' => ['nullable', 'numeric', 'min:0'], 'configuration.window_minutes' => ['nullable', 'integer', 'min:1', 'max:10080'], 'is_active' => ['sometimes', 'boolean'], 'note' => ['nullable', 'string', 'max:2000']]); }
    private function authorize(Request $request): void { abort_unless($request->user() instanceof Admin && $request->user()->role === 'super_admin', 403); }
}
