<?php

namespace Tests\Feature;

use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\User;
use Database\Seeders\ResetToAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ResetToAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_removes_tenant_data_and_keeps_existing_admin_login(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'active' => true, 'username' => 'original-admin']);
        User::factory()->create(['is_admin' => true, 'active' => true]);
        User::factory()->create(['is_admin' => true, 'active' => false]);
        User::factory()->create(['is_admin' => false]);
        $deployment = LtiDeployment::factory()->create();
        $identity = LtiIdentity::factory()->create(['lti_platform_id' => $deployment->lti_platform_id]);
        H5pContent::factory()->create(['tenant_id' => $deployment->tenant_id, 'lti_identity_id' => $identity->id]);
        DB::table('tenant_identity')->insert([
            'tenant_id' => $deployment->tenant_id, 'lti_identity_id' => $identity->id, 'roles' => '[]',
        ]);

        $adminAccounts = DB::table('users')->where('is_admin', true)->orderBy('id')->get();

        $this->seed(ResetToAdminSeeder::class);
        $this->seed(ResetToAdminSeeder::class);

        foreach (['tenants', 'h5p_contents', 'tenant_identity', 'lti_identities', 'lti_platforms', 'lti_deployments'] as $table) {
            $this->assertDatabaseEmpty($table);
        }

        $this->assertEquals($adminAccounts, DB::table('users')->orderBy('id')->get());
        $this->assertSame(1, H5pLibrary::count());
        $this->post(route('admin.authenticate'), ['username' => 'original-admin', 'password' => 'password'])
            ->assertRedirect(route('admin.index'));
        $this->assertAuthenticatedAs($admin);
    }

    public function test_reset_aborts_without_an_active_admin(): void
    {
        User::factory()->create(['is_admin' => true, 'active' => false]);
        User::factory()->create(['is_admin' => false, 'active' => true]);
        $deployment = LtiDeployment::factory()->create();

        try {
            $this->seed(ResetToAdminSeeder::class);
            $this->fail('Reset requires an active system Admin.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cần ít nhất một Admin hệ thống đang hoạt động trước khi reset.', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('lti_deployments', ['id' => $deployment->id]);
    }
}
