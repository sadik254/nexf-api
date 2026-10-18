<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Seller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitePopupTest extends TestCase
{
    use RefreshDatabase;

    private function popup(array $overrides = []): array
    {
        return [
            'id' => 'pop-1', 'name' => 'Eid sale', 'enabled' => true, 'kind' => 'image',
            'image' => 'https://ucarecdn.com/abc/', 'href' => '/offers', 'icon' => 'Gift',
            'eyebrow' => '', 'title' => '', 'body' => '', 'points' => [], 'cta' => '',
            'theme' => 'orange', 'pages' => 'home', 'frequency' => 'daily', 'delay' => 1,
            ...$overrides,
        ];
    }

    private function token(string $role): string
    {
        $admin = Admin::create(['name' => $role, 'email' => "{$role}-popup@example.test", 'password' => 'password123', 'role' => $role, 'is_active' => true]);
        return $admin->createToken('test', ['admin:basic'])->plainTextToken;
    }

    public function test_admins_and_super_admins_manage_popups(): void
    {
        $this->getJson('/api/popups')->assertOk()->assertExactJson([]);

        $tokens = ['admin' => $this->token('admin'), 'super_admin' => $this->token('super_admin')];
        foreach ($tokens as $role => $token) {
            $this->withToken($token)
                ->postJson('/api/admin/popups', ['popups' => [$this->popup(['name' => $role])]])
                ->assertOk()
                ->assertJsonPath('0.name', $role);
        }
        $this->withToken($tokens['admin'])->getJson('/api/admin/popups')->assertOk()->assertJsonCount(1);
    }

    public function test_public_list_hides_off_and_out_of_date_popups_and_names(): void
    {
        $token = $this->token('admin');
        $this->withToken($token)->postJson('/api/admin/popups', ['popups' => [
            $this->popup(['id' => 'pop-1', 'enabled' => false]),
            $this->popup(['id' => 'pop-2', 'endsAt' => now()->subDay()->toDateString()]),
            $this->popup(['id' => 'pop-3', 'startsAt' => now()->addDay()->toDateString()]),
            $this->popup(['id' => 'pop-4', 'kind' => 'text', 'image' => '', 'title' => 'Free delivery', 'points' => [['icon' => 'Truck', 'text' => 'All week']], 'pointsLayout' => 'row', 'cta' => 'Shop now']),
        ]])->assertOk()->assertJsonCount(4);

        $this->getJson('/api/popups')->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', 'pop-4')
            ->assertJsonPath('0.name', '')
            ->assertJsonPath('0.points.0.text', 'All week')
            ->assertJsonPath('0.pointsLayout', 'row');
    }

    public function test_validation(): void
    {
        $token = $this->token('admin');
        $post = fn (array $p) => $this->withToken($token)->postJson('/api/admin/popups', ['popups' => [$this->popup($p)]]);
        $post(['image' => ''])->assertUnprocessable()->assertJsonValidationErrors('popups.0.image');
        $post(['kind' => 'text', 'title' => ''])->assertUnprocessable()->assertJsonValidationErrors('popups.0.title');
        $post(['href' => 'javascript:alert(1)'])->assertUnprocessable()->assertJsonValidationErrors('popups.0.href');
        $post(['startsAt' => '2026-10-10', 'endsAt' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('popups.0.endsAt');
    }

    public function test_guests_cannot_edit(): void
    {
        $this->postJson('/api/admin/popups', ['popups' => []])->assertUnauthorized();
    }
}
