<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MediaAsset;
use App\Models\Reseller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfilePhotoCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_removing_a_customer_direct_upload_deletes_only_an_orphaned_r2_object(): void
    {
        config()->set('filesystems.disks.r2.url', 'https://media.example.test');
        Storage::fake('r2');
        $url = 'https://media.example.test/customers/2026/11/old-photo.jpg';
        Storage::disk('r2')->put('customers/2026/11/old-photo.jpg', 'photo');
        $customer = Customer::create(['name' => 'Photo owner', 'email' => 'photo-owner@example.test', 'password' => 'password123', 'profile_picture' => $url]);

        $this->withToken($customer->createToken('test', ['customer:basic'])->plainTextToken)
            ->postJson('/api/customers/me', ['remove_profile_picture' => true])
            ->assertOk()->assertJsonPath('customer.profile_picture', null);
        Storage::disk('r2')->assertMissing('customers/2026/11/old-photo.jpg');
    }

    public function test_a_shared_profile_photo_and_media_library_photo_are_never_removed_by_an_account_update(): void
    {
        config()->set('filesystems.disks.r2.url', 'https://media.example.test');
        Storage::fake('r2');
        $sharedUrl = 'https://media.example.test/customers/2026/11/shared-photo.jpg';
        $libraryUrl = 'https://media.example.test/media/2026/11/library-photo.jpg';
        Storage::disk('r2')->put('customers/2026/11/shared-photo.jpg', 'shared');
        Storage::disk('r2')->put('media/2026/11/library-photo.jpg', 'library');
        $first = Customer::create(['name' => 'First', 'email' => 'shared-first@example.test', 'password' => 'password123', 'profile_picture' => $sharedUrl]);
        Customer::create(['name' => 'Second', 'email' => 'shared-second@example.test', 'password' => 'password123', 'profile_picture' => $sharedUrl]);
        $reseller = Reseller::create(['name' => 'Library reseller', 'email' => 'library-reseller@example.test', 'password' => 'password123', 'is_active' => true, 'image' => $libraryUrl]);
        MediaAsset::create(['owner_type' => 'admin', 'owner_id' => 1, 'source' => 'upload', 'url' => $libraryUrl, 'file_name' => 'library-photo.jpg', 'mime_type' => 'image/jpeg']);

        $this->withToken($first->createToken('test', ['customer:basic'])->plainTextToken)
            ->postJson('/api/customers/me', ['remove_profile_picture' => true])->assertOk();
        $this->withToken($reseller->createToken('test', ['reseller:basic'])->plainTextToken)
            ->postJson('/api/resellers/me', ['clear_image' => true])->assertOk();

        Storage::disk('r2')->assertExists('customers/2026/11/shared-photo.jpg');
        Storage::disk('r2')->assertExists('media/2026/11/library-photo.jpg');
    }
}
