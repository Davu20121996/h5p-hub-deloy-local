<?php

namespace Tests\Feature;

use App\H5p\LibraryInstaller;
use App\H5p\Runtime;
use App\Models\H5pContent;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class TenantAccountTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/'.Str::uuid());
        File::ensureDirectoryExists($this->root);
        config(['lti.storage_directory' => $this->root, 'lti.key_directory' => $this->root.'/keys']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function administrator(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    /** @return array{name: string, email: string, username: string, password: string, password_confirmation: string} */
    private function accountData(string $username): array
    {
        return ['name' => $username, 'email' => $username.'@example.com', 'username' => $username,
            'password' => 'secure-password-123', 'password_confirmation' => 'secure-password-123'];
    }

    private function tenantAccount(LtiDeployment $deployment, string $role = 'tenant-admin'): User
    {
        $identity = LtiIdentity::factory()->create(['lti_platform_id' => $deployment->lti_platform_id]);
        $user = User::factory()->create(['password' => 'secure-password-123']);
        $user->forceFill(['username' => 'account-'.$user->id, 'tenant_id' => $deployment->tenant_id,
            'lti_deployment_id' => $deployment->id, 'lti_identity_id' => $identity->id, 'role' => $role])->save();

        return $user;
    }

    private function signIn(User $user): void
    {
        $this->post(route('account.authenticate'), ['username' => $user->username, 'password' => 'secure-password-123'])->assertRedirect(route('account.index'));
    }

    public function test_tenant_admin_views_and_manages_only_their_assigned_tenant(): void
    {
        $deployment = LtiDeployment::factory()->create();
        $manager = $this->tenantAccount($deployment);
        $content = H5pContent::factory()->create(['tenant_id' => $manager->tenant_id, 'lti_identity_id' => $manager->lti_identity_id, 'title' => 'Own tenant lesson']);
        $foreign = H5pContent::factory()->create(['h5p_library_id' => $content->h5p_library_id, 'lti_identity_id' => $manager->lti_identity_id, 'title' => 'Foreign private lesson']);
        $this->signIn($manager);
        $this->get(route('account.index', ['tab' => 'profile']))->assertOk()->assertSee(route('account.tenant.update'));
        $this->get(route('account.index', ['tab' => 'connections']))->assertOk()->assertSee($deployment->deployment_id)->assertSee('RSA public key')->assertDontSee('Tạo khóa RSA');
        $this->get(route('account.index', ['tab' => 'contents']))->assertOk()->assertSee('Own tenant lesson')->assertDontSee('Foreign private lesson');
        $this->put(route('account.tenant.update'), ['name' => 'Updated tenant', 'contact_email' => 'contact@example.com', 'active' => '0', 'slug' => 'unapproved-change'])->assertSessionHasNoErrors();
        $this->assertSame('Updated tenant', $manager->tenant->fresh()->name);
        $this->assertTrue($manager->tenant->fresh()->active);
        $this->assertNotSame('unapproved-change', $manager->tenant->fresh()->slug);
        $this->patch(route('account.contents.update', $content), ['status' => 'draft', 'admin_hidden' => '1'])->assertSessionHasNoErrors();
        $this->patch(route('account.contents.update', $foreign), ['status' => 'draft', 'admin_hidden' => '1'])->assertNotFound();
        $this->get(route('account.contents.preview', $foreign))->assertNotFound();
        $this->get(route('admin.tenants.show', $manager->tenant_id))->assertForbidden();
        $teacher = $this->tenantAccount($deployment, 'teacher');
        $this->post(route('account.logout'));
        $this->signIn($teacher);
        $this->put(route('account.tenant.update'), ['name' => 'Illegal update'])->assertForbidden();
        $this->patch(route('account.contents.update', $content), ['status' => 'published', 'admin_hidden' => '0'])->assertForbidden();
        $this->get(route('account.contents.preview', $content))->assertForbidden();
    }

    public function test_admin_can_issue_a_tenant_admin_with_an_email_as_username(): void
    {
        $deployment = LtiDeployment::factory()->create();
        $data = array_replace($this->accountData('manager'), ['username' => 'vu.tran@gmail.com', 'email' => 'vu.tran@gmail.com', 'deployment_id' => $deployment->id]);
        $this->actingAs($this->administrator())->post(route('admin.tenants.accounts.store', $deployment->tenant), $data)->assertSessionHasNoErrors()->assertRedirect();
        $account = User::where('username', 'vu.tran@gmail.com')->sole();
        $this->assertSame('tenant-admin', $account->role);
        $this->post(route('admin.logout'));
        $this->signIn($account);
        $this->get(route('account.index'))->assertOk()->assertSee('Cấp tài khoản giáo viên');
    }

    public function test_admin_issues_tenant_admin_and_tenant_admin_issues_teacher_with_fixed_roles(): void
    {
        $admin = $this->administrator();
        $deployment = LtiDeployment::factory()->create();
        $tenant = $deployment->tenant;
        $this->actingAs($admin)->get(route('admin.tenants.show', [$tenant, 'tab' => 'accounts']))->assertOk()->assertSee('Cấp tài khoản tenant-admin');
        $this->post(route('admin.tenants.accounts.store', $tenant), $this->accountData('manager') + ['deployment_id' => $deployment->id, 'role' => 'teacher'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.tenants.show', [$tenant, 'tab' => 'accounts']));
        $manager = User::where('username', 'manager')->firstOrFail();
        $this->assertSame('tenant-admin', $manager->role);
        $this->assertSame($tenant->id, $manager->tenant_id);
        $this->assertSame($admin->id, $manager->created_by);
        $this->assertTrue(Hash::check('secure-password-123', $manager->password));
        $this->post(route('admin.logout'));
        $this->signIn($manager);
        $this->get(route('account.index'))->assertOk()->assertSee('Cấp tài khoản giáo viên')->assertSee('Kết nối LMS');
        $other = Tenant::factory()->create();
        $this->post(route('account.teachers.store'), $this->accountData('teacher-one') + ['role' => 'tenant-admin', 'is_admin' => true, 'tenant_id' => $other->id])
            ->assertSessionHasNoErrors()->assertRedirect(route('account.index'));
        $teacher = User::where('username', 'teacher-one')->firstOrFail();
        $this->assertSame('teacher', $teacher->role);
        $this->assertFalse($teacher->is_admin);
        $this->assertSame($tenant->id, $teacher->tenant_id);
        $this->assertSame($manager->id, $teacher->created_by);
        $this->get(route('account.index'))->assertOk()->assertSee('teacher-one');
        $this->post(route('admin.tenants.accounts.store', $tenant), $this->accountData('illegal'))->assertForbidden();
        $this->post(route('admin.tenants.connections.store', $tenant))->assertForbidden();
        $this->post(route('account.logout'));
        $this->signIn($teacher);
        $this->get(route('account.index'))->assertRedirect(route('account.workspace'));
        $this->post(route('account.teachers.store'), $this->accountData('illegal'))->assertForbidden();
        $this->patch(route('account.teachers.update', $manager), ['active' => '0'])->assertForbidden();
        $this->get(route('account.workspace'))->assertRedirect();
        $launch = LtiLaunch::where('lti_identity_id', $teacher->lti_identity_id)->sole();
        $this->get(route('workspace.index', $launch))->assertOk()->assertSee('Nhập bài .h5p')->assertDontSee('Import thư viện H5P')->assertDontSee('Quản trị đơn vị');
        $this->get(route('workspace.tenant', $launch))->assertForbidden();
        $this->post(route('lti.complete', $launch))->assertForbidden();
    }

    public function test_teacher_imports_content_using_only_administrator_installed_libraries(): void
    {
        $deployment = LtiDeployment::factory()->create();
        $teacher = $this->tenantAccount($deployment, 'teacher');
        $this->signIn($teacher);
        $this->get(route('account.workspace'))->assertRedirect();
        $launch = LtiLaunch::sole();
        $path = $this->root.'/lesson.h5p';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (File::files(resource_path('examples/demo-library')) as $file) {
            $zip->addFile($file->getPathname(), 'Hub.DemoQuestion-1.0/'.$file->getFilename());
        }
        $zip->addFromString('h5p.json', json_encode(['title' => 'Local teacher lesson', 'mainLibrary' => 'Hub.DemoQuestion', 'preloadedDependencies' => [['machineName' => 'Hub.DemoQuestion', 'majorVersion' => 1, 'minorVersion' => 0]]]));
        $zip->addFromString('content/content.json', json_encode(['question' => 'Imported?', 'answer' => true]));
        $zip->close();
        $this->post(route('content.import', $launch), ['package' => new UploadedFile($path, 'lesson.h5p', test: true)])->assertSessionHasErrors('package');
        $this->assertDatabaseCount('h5p_libraries', 0);
        $this->assertDatabaseCount('h5p_contents', 0);
        $this->post(route('workspace.tenant.install', $launch), ['package' => new UploadedFile($path, 'lesson.h5p', test: true)])->assertForbidden();
        (new LibraryInstaller)->install($path);
        $script = File::get(Runtime::libraryPath().'/Hub.DemoQuestion-1.0/question.js');
        $zip->open($path);
        $manifest = json_decode($zip->getFromName('Hub.DemoQuestion-1.0/library.json'), true);
        $manifest['patchVersion'] = 999;
        $zip->addFromString('Hub.DemoQuestion-1.0/library.json', json_encode($manifest));
        $zip->addFromString('Hub.DemoQuestion-1.0/question.js', 'alert(document.cookie)');
        $zip->close();
        $this->post(route('content.import', $launch), ['package' => new UploadedFile($path, 'lesson.h5p', test: true), 'publish' => '1'])->assertSessionHasNoErrors()->assertRedirect(route('workspace.index', $launch));
        $this->assertSame($script, File::get(Runtime::libraryPath().'/Hub.DemoQuestion-1.0/question.js'));
        $this->assertDatabaseMissing('h5p_libraries', ['patch_version' => 999]);
        $content = H5pContent::sole();
        $this->assertSame('published', $content->status);
        $this->get(route('workspace.index', $launch))->assertOk()->assertDontSee('Kết quả');
        $this->get(route('content.results.index', [$launch, $content->uuid]))->assertRedirect(route('workspace.index', $launch));
        $this->get(route('content.create', $launch))->assertOk()->assertSee('Nhập bài .h5p')->assertDontSee('h5p-editor');
        $this->patch(route('content.status', [$launch, $content->uuid]), ['status' => 'draft'])->assertSessionHasNoErrors();
        $this->assertSame('draft', $content->fresh()->status);
        $this->assertSame($teacher->tenant_id, $content->tenant_id);
        $this->assertSame($teacher->lti_identity_id, $content->lti_identity_id);
        $this->get(route('content.edit', [$launch, $content->uuid]))->assertOk();
        $this->post(route('account.logout'));
        $this->get(route('content.edit', [$launch, $content->uuid]))->assertUnauthorized();
        $otherTeacher = $this->tenantAccount($deployment, 'teacher');
        $this->signIn($otherTeacher);
        $this->get(route('account.workspace'));
        $otherLaunch = LtiLaunch::where('lti_identity_id', $otherTeacher->lti_identity_id)->sole();
        $this->get(route('content.play', [$otherLaunch, $content->uuid]))->assertForbidden();
        $this->post(route('account.logout'));
        $manager = $this->tenantAccount($deployment);
        $this->signIn($manager);
        $response = $this->get(route('account.contents.preview', $content))->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk();
        $content->update(['admin_hidden' => true]);
        $this->get(route('account.contents.preview', $content))->assertForbidden();
    }

    public function test_forms_reject_markup_and_login_injection_without_rejecting_legitimate_names(): void
    {
        $this->post(route('account.authenticate'), ['username' => "admin' OR 1=1 --", 'password' => 'secure-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $deployment = LtiDeployment::factory()->create();
        $this->actingAs($this->administrator());
        $data = $this->accountData('safe-account') + ['deployment_id' => $deployment->id];
        foreach (['<script>alert(1)</script>', '<img src=x onerror=alert(1)>', "Name\x00Injected"] as $name) {
            $this->post(route('admin.tenants.accounts.store', $deployment->tenant), array_replace($data, ['name' => $name]))->assertSessionHasErrors('name');
        }
        $this->assertDatabaseCount('lti_identities', 0);
        $this->post(route('admin.tenants.accounts.store', $deployment->tenant), array_replace($data, ['name' => "Trần O'Brien"]))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'safe-account', 'name' => "Trần O'Brien"]);
        $this->post(route('account.logout'));
        $manager = $this->tenantAccount($deployment);
        $this->signIn($manager);
        $this->put(route('account.tenant.update'), ['name' => 'Valid tenant', 'notes' => '<script>alert(1)</script>'])->assertSessionHasErrors('notes');
        $this->put(route('account.tenant.update'), ['name' => 'Valid tenant', 'contact_phone' => '$(whoami)'])->assertSessionHasErrors('contact_phone');
        $this->assertNotSame('Valid tenant', $deployment->tenant->fresh()->name);
    }

    public function test_accounts_are_scoped_and_password_reset_revokes_old_sessions(): void
    {
        $deployment = LtiDeployment::factory()->create();
        $manager = $this->tenantAccount($deployment);
        $teacher = $this->tenantAccount($deployment, 'teacher');
        $otherDeployment = LtiDeployment::factory()->create(['lti_platform_id' => $deployment->lti_platform_id]);
        $foreign = $this->tenantAccount($otherDeployment, 'teacher');
        $this->signIn($teacher);
        $this->get(route('account.workspace'));
        $launch = LtiLaunch::sole();
        $teacherSession = hash('sha256', $teacher->password);
        $this->post(route('account.logout'));
        $this->signIn($manager);
        $this->get(route('account.index'))->assertOk()->assertDontSee($foreign->email);
        $this->get(route('workspace.index', $launch))->assertUnauthorized();
        $this->patch(route('account.teachers.update', $foreign), ['active' => '0'])->assertNotFound();
        $this->patch(route('account.teachers.update', $manager), ['active' => '0'])->assertNotFound();
        $this->patch(route('account.teachers.update', $teacher), ['active' => '1', 'password' => 'replacement-password', 'password_confirmation' => 'replacement-password'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('replacement-password', $teacher->fresh()->password));
        $this->assertTrue($launch->fresh()->expires_at->isPast());
        $this->actingAs($teacher->fresh())->withSession(['account_password' => $teacherSession, 'lti_launches.'.$launch->id => true]);
        $this->get(route('account.workspace'))->assertUnauthorized();
        $this->get(route('workspace.index', $launch))->assertForbidden();
    }

    public function test_admin_can_lock_tenant_admin_but_cannot_manage_another_tenants_account(): void
    {
        $admin = $this->administrator();
        $deployment = LtiDeployment::factory()->create();
        $manager = $this->tenantAccount($deployment);
        $this->actingAs($admin)->patch(route('admin.tenants.accounts.update', [Tenant::factory()->create(), $manager]), ['active' => '0'])->assertNotFound();
        $this->patch(route('admin.tenants.accounts.update', [$deployment->tenant, $manager]), ['active' => '0'])->assertSessionHasNoErrors();
        $this->post(route('admin.logout'));
        $this->post(route('account.authenticate'), ['username' => $manager->username, 'password' => 'secure-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_creation_validates_credentials_and_requires_an_active_connection_in_the_tenant(): void
    {
        $admin = $this->administrator();
        $deployment = LtiDeployment::factory()->create();
        $this->actingAs($admin);
        $this->post(route('admin.tenants.accounts.store', Tenant::factory()->create()), $this->accountData('manager') + ['deployment_id' => $deployment->id])->assertNotFound();
        $this->post(route('admin.tenants.accounts.store', $deployment->tenant), array_replace($this->accountData('manager'), ['password' => 'short']) + ['deployment_id' => $deployment->id])->assertSessionHasErrors('password');
        $this->post(route('admin.tenants.accounts.store', $deployment->tenant), $this->accountData('manager') + ['deployment_id' => $deployment->id])->assertSessionHasNoErrors();
        $this->post(route('admin.tenants.accounts.store', $deployment->tenant), $this->accountData('manager') + ['deployment_id' => $deployment->id])->assertSessionHasErrors(['username', 'email']);
        $this->assertDatabaseCount('lti_identities', 1);
        $deployment->update(['active' => false]);
        $this->post(route('admin.tenants.accounts.store', $deployment->tenant), $this->accountData('new-manager') + ['deployment_id' => $deployment->id])->assertNotFound();
        $this->post(route('admin.logout'));
        $this->post(route('account.authenticate'), ['username' => 'manager', 'password' => 'secure-password-123'])->assertSessionHasErrors('username');
        $this->assertGuest();
        $this->get(route('account.index'))->assertRedirect(route('account.login'));
    }
}
