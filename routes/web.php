<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\InitialSetupController;
use App\Models\Admin;
use App\Models\Store;
use Illuminate\Support\Facades\Schema;

Route::get('/', function () {
    if (Schema::hasTable('stores') && Schema::hasTable('admins')
        && !Store::query()->exists()
        && !Admin::query()->where('role', 'super_admin')->exists()) {
        return redirect()->route('setup.create');
    }

    return view('welcome');
})->name('home');

Route::get('/setup', [InitialSetupController::class, 'create'])->name('setup.create');
Route::post('/setup', [InitialSetupController::class, 'store'])->name('setup.store');
