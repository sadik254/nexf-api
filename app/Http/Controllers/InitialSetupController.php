<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class InitialSetupController extends Controller
{
    public function create(): View
    {
        abort_if($this->isConfigured(), 404);

        return view('setup', ['areas' => $this->areas()]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($this->isConfigured(), 404);

        $request->merge([
            'admin_email' => strtolower(trim((string) $request->input('admin_email'))),
            'store_email' => strtolower(trim((string) $request->input('store_email'))),
            'sku_prefix' => strtoupper(trim((string) $request->input('sku_prefix'))),
        ]);
        $areas = $this->areas();
        $district = (string) $request->input('address_district');
        $thanas = $areas['thanas'][$district] ?? [];

        $data = $request->validate([
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('admins', 'email')],
            'admin_phone' => ['required', 'regex:/^01[3-9][0-9]{8}$/', Rule::unique('admins', 'phone')],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'store_name' => ['required', 'string', 'max:255'],
            'sku_prefix' => ['required', 'regex:/^[A-Z0-9]{4}$/', Rule::unique('sellers', 'sku_prefix')],
            'store_email' => ['required', 'email', 'max:255'],
            'store_phone' => ['required', 'regex:/^01[3-9][0-9]{8}$/'],
            'address_line' => ['required', 'string', 'max:255'],
            'address_district' => ['required', Rule::in($areas['districts'])],
            'address_area' => ['required', Rule::in($thanas)],
            'store_logo' => ['nullable', 'file', 'image', 'max:5120'],
        ], [
            'admin_email.unique' => 'Email already taken.',
            'admin_phone.unique' => 'Mobile number already taken.',
            'admin_phone.regex' => 'Enter a valid mobile number.',
            'admin_password.confirmed' => "Passwords don't match.",
            'sku_prefix.regex' => 'Use exactly 4 letters or digits.',
            'sku_prefix.unique' => 'SKU code already taken.',
            'store_phone.regex' => 'Enter a valid store mobile number.',
            'address_district.in' => 'Select your district from the list.',
            'address_area.in' => 'Select your thana from the list.',
        ]);

        $storeLogo = $request->hasFile('store_logo')
            ? app(\App\Services\MediaUploadService::class)->upload($request->file('store_logo'), 'stores')
            : null;

        try {
            DB::transaction(function () use ($data, $storeLogo) {
                if ($this->isConfigured()) {
                    abort(404);
                }

                Store::create([
                    'setup_key' => Store::PRIMARY_KEY,
                    'name' => $data['store_name'],
                    'logo' => $storeLogo,
                    'sku_prefix' => $data['sku_prefix'],
                    'support_email' => $data['store_email'],
                    'support_phone' => $data['store_phone'],
                    'address_line' => $data['address_line'],
                    'address_area' => $data['address_area'],
                    'address_district' => $data['address_district'],
                    'store_address' => implode(', ', [$data['address_line'], $data['address_area'], $data['address_district']]),
                ]);

                Admin::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'phone' => $data['admin_phone'],
                    'role' => 'super_admin',
                    'is_active' => true,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return back()->withInput()->withErrors(['admin_email' => 'Email already taken.']);
        }

        return redirect()->route('home')->with('status', 'Your store is set up. Sign in to the console to get started.');
    }

    private function isConfigured(): bool
    {
        return Store::query()->exists() || Admin::query()->where('role', 'super_admin')->exists();
    }

    /** @return array{districts: list<string>, thanas: array<string, list<string>>} */
    private function areas(): array
    {
        static $areas;
        return $areas ??= json_decode((string) file_get_contents(resource_path('brand/bd-areas.json')), true, 512, JSON_THROW_ON_ERROR);
    }

}
