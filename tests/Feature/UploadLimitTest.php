<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Customer;
use App\Services\MediaUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_endpoints_accept_large_videos_and_reject_an_oversized_photo(): void
    {
        $admin = Admin::create(['name' => 'Media admin', 'email' => 'upload-limits-admin@example.test', 'password' => 'password123', 'role' => 'super_admin', 'is_active' => true]);
        $customer = Customer::create(['name' => 'Buyer', 'email' => 'upload-limits-customer@example.test', 'password' => 'password123']);
        $adminToken = $admin->createToken('test', ['admin'])->plainTextToken;
        $customerToken = $customer->createToken('test', ['customer:basic'])->plainTextToken;

        $this->mock(MediaUploadService::class, function ($mock): void {
            $mock->shouldReceive('upload')->times(4)->andReturn(
                'https://media.example.test/media/video.mp4',
                'https://media.example.test/media/review.mov',
                'https://media.example.test/media/support.mov',
                'https://media.example.test/media/chat.mov',
            );
        });

        $this->withToken($adminToken)->post('/api/admin/media', [
            'file' => UploadedFile::fake()->create('library-video.mp4', 90 * 1024, 'video/mp4'),
        ])->assertCreated();

        $this->withToken($customerToken)->post('/api/customers/review-media', [
            'file' => UploadedFile::fake()->create('review-video.mov', 50 * 1024, 'video/quicktime'),
        ])->assertCreated();
        $this->withToken($customerToken)->post('/api/customers/support-attachments', [
            'file' => UploadedFile::fake()->create('support-video.mov', 50 * 1024, 'video/quicktime'),
        ])->assertOk();
        $this->withToken($customerToken)->post('/api/customers/chat-attachments', [
            'file' => UploadedFile::fake()->create('chat-video.mov', 50 * 1024, 'video/quicktime'),
        ])->assertOk();

        $this->withToken($adminToken)->withHeader('Accept', 'application/json')->post('/api/admin/media', [
            'file' => UploadedFile::fake()->image('too-large.jpg')->size(11 * 1024),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }
}
