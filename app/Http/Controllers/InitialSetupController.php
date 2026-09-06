<?php

namespace App\Http\Controllers;

use App\Models\Admin;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Uploadcare\Api;
use Uploadcare\Configuration;

class InitialSetupController extends Controller
{
    public function create(): View
    {
        abort_if($this->isConfigured(), 404);

        return view('setup');
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if($this->isConfigured(), 404);

        $data = $request->validate([
            'store_name' => ['required', 'string', 'max:255'],
            'store_logo' => ['nullable', 'file', 'image', 'max:5120'],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255', 'unique:admins,email'],
            'admin_password' => ['required', 'string', 'min:8', 'confirmed'],
            'admin_phone' => ['nullable', 'string', 'max:32', 'unique:admins,phone'],
            'admin_address' => ['nullable', 'string'],
            'admin_image' => ['nullable', 'file', 'image', 'max:5120'],
        ]);

        $uploadcare = $request->hasFile('store_logo') || $request->hasFile('admin_image')
            ? $this->uploadcare()
            : null;
        $storeLogo = $request->hasFile('store_logo')
            ? $this->upload($uploadcare, $request->file('store_logo')->getPathname())
            : null;
        $adminImage = $request->hasFile('admin_image')
            ? $this->upload($uploadcare, $request->file('admin_image')->getPathname())
            : null;

        try {
            DB::transaction(function () use ($data, $storeLogo, $adminImage) {
                if ($this->isConfigured()) {
                    abort(404);
                }

                Store::create([
                    'setup_key' => Store::PRIMARY_KEY,
                    'name' => $data['store_name'],
                    'logo' => $storeLogo,
                ]);

                Admin::create([
                    'name' => $data['admin_name'],
                    'email' => $data['admin_email'],
                    'password' => $data['admin_password'],
                    'phone' => $data['admin_phone'] ?? null,
                    'address' => $data['admin_address'] ?? null,
                    'image' => $adminImage,
                    'role' => 'super_admin',
                    'is_active' => true,
                ]);
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            abort(404);
        }

        return redirect()->route('home')->with('status', 'Initial setup completed. You can now log in as the super admin.');
    }

    private function isConfigured(): bool
    {
        return Store::query()->exists() || Admin::query()->where('role', 'super_admin')->exists();
    }

    private function uploadcare(): Api
    {
        return new Api(Configuration::create(
            config('services.uploadcare.public_key'),
            config('services.uploadcare.secret_key')
        ));
    }

    private function upload(Api $uploadcare, string $path): string
    {
        $file = $uploadcare->uploader()->fromPath($path);

        return "https://ucarecdn.com/{$file->getUuid()}/-/preview/";
    }
}
