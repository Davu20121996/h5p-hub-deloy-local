<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function administrator(): User
    {
        $user = User::factory()->create(['password' => Hash::make('secure-password-123')]);
        $user->forceFill(['username' => 'administrator', 'is_admin' => true])->save();

        return $user;
    }

    public function test_guests_cannot_access_admin_actions(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
        $this->post('/admin/registrations')->assertRedirect('/admin/login');
        $this->post('/admin/libraries')->assertRedirect('/admin/login');
        $this->get('/admin/login')->assertOk()->assertSee('Đăng nhập quản trị');
    }

    public function test_admin_can_login_view_dashboard_and_logout(): void
    {
        $admin = $this->administrator();
        $this->post('/admin/login', ['username' => 'administrator', 'password' => 'secure-password-123'])->assertRedirect('/admin');
        $this->assertAuthenticatedAs($admin);
        $this->get('/admin')->assertOk()->assertSee('Kết nối LTI')->assertSee('Thư viện H5P');
        $this->post('/admin/logout')->assertRedirect('/admin/login');
        $this->assertGuest();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_invalid_password_and_non_admin_are_rejected(): void
    {
        $admin = $this->administrator();
        $this->post('/admin/login', ['username' => 'administrator', 'password' => 'wrong'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $admin->forceFill(['is_admin' => false])->save();
        $this->post('/admin/login', ['username' => 'administrator', 'password' => 'secure-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->actingAs($admin)->get('/admin')->assertForbidden();
        $this->post('/admin/registrations')->assertForbidden();
        $this->post('/admin/libraries')->assertForbidden();
    }

    public function test_login_attempts_are_throttled(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/admin/login', ['username' => 'unknown', 'password' => 'wrong'])->assertRedirect();
        }
        $this->post('/admin/login', ['username' => 'unknown', 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_admin_can_register_platform_and_invalid_json_does_not_create_data(): void
    {
        $this->actingAs($this->administrator());
        $data = ['tenant_slug' => 'school', 'tenant_name' => 'School', 'name' => 'Moodle', 'issuer' => 'https://lms.example.com', 'client_id' => 'client', 'authorization_url' => 'https://lms.example.com/authorize', 'jwks_url' => 'https://lms.example.com/jwks', 'deployment_id' => 'deployment', 'allowed_origins' => ['https://lms.example.com']];
        $this->post('/admin/registrations', ['registration' => UploadedFile::fake()->createWithContent('registration.json', json_encode($data))])->assertSessionHasNoErrors()->assertSessionHas('status');
        $this->assertDatabaseHas('lti_platforms', ['client_id' => 'client']);
        $this->assertDatabaseHas('tenants', ['slug' => 'school']);
        $this->post('/admin/registrations', ['registration' => UploadedFile::fake()->createWithContent('invalid.json', '{')])->assertSessionHasErrors('registration');
        $this->assertDatabaseCount('lti_platforms', 1);
    }

    public function test_library_upload_is_required_and_invalid_package_is_rejected(): void
    {
        $this->actingAs($this->administrator());
        $this->post('/admin/libraries')->assertSessionHasErrors('package');
        $this->post('/admin/libraries', ['package' => UploadedFile::fake()->createWithContent('invalid.h5p', 'invalid')])->assertSessionHasErrors('package');
        $this->assertDatabaseCount('h5p_libraries', 0);
    }

    public function test_command_creates_admin_with_hashed_password(): void
    {
        $this->artisan('admin:create', ['username' => 'operator'])
            ->expectsQuestion('Password (at least 12 characters)', 'secure-password-123')
            ->expectsOutput('Administrator created. Login at /admin/login.')
            ->assertSuccessful();
        $admin = User::where('username', 'operator')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('secure-password-123', $admin->password));
    }
}
