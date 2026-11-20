<?php

namespace Tests\Feature;

use App\Lti\KeyStore;
use App\Lti\PublishScore;
use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class AdminTenantTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/'.Str::uuid());
        File::ensureDirectoryExists($this->root);
        config(['lti.storage_directory' => $this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function loginAdmin(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
    }

    private function connectionData(): array
    {
        return ['name' => 'School LMS', 'issuer' => 'https://school.example.com', 'client_id' => 'school-client',
            'authorization_url' => 'https://school.example.com/authorize', 'jwks_url' => 'https://school.example.com/jwks',
            'deployment_id' => 'school-deployment', 'allowed_origins' => "https://school.example.com\nhttps://portal.example.com", 'platform_active' => '1', 'active' => '1'];
    }

    public function test_ags_token_url_is_saved_per_lms_and_rejects_untrusted_origins(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create();
        $data = $this->connectionData() + ['ags_token_url' => 'https://school.example.com/lti/token'];
        $this->post(route('admin.tenants.connections.store', $tenant), $data)->assertSessionHasNoErrors();
        $deployment = LtiDeployment::with('platform')->sole();
        $this->assertSame($data['ags_token_url'], PublishScore::tokenUrl($deployment->platform));
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'connections']))->assertOk()->assertSee('AGS Token URL')->assertSee($data['ags_token_url']);
        $this->put(route('admin.tenants.connections.update', [$tenant, $deployment]), array_replace($data, ['ags_token_url' => 'https://foreign.example/token']))->assertSessionHasErrors('ags_token_url');
        $this->assertSame($data['ags_token_url'], $deployment->platform->fresh()->ags_token_url);
        $this->put(route('admin.tenants.connections.update', [$tenant, $deployment]), array_replace($data, ['ags_token_url' => '']))->assertSessionHasNoErrors();
        $this->assertNull(PublishScore::tokenUrl($deployment->platform->fresh()));
    }

    public function test_admin_forms_reject_markup_and_search_treats_sql_as_literal_text(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create(['name' => 'Private tenant']);
        $this->post(route('admin.tenants.store'), ['slug' => 'unsafe', 'name' => '<script>alert(1)</script>', 'active' => '1'])->assertSessionHasErrors('name');
        $this->assertDatabaseMissing('tenants', ['slug' => 'unsafe']);
        $this->post(route('admin.tenants.connections.store', $tenant), array_replace($this->connectionData(), ['client_id' => '<img src=x onerror=alert(1)>']))->assertSessionHasErrors('client_id');
        $this->post(route('admin.tenants.connections.store', $tenant), array_replace($this->connectionData(), ['authorization_url' => 'javascript:alert(1)']))->assertSessionHasErrors('authorization_url');
        $this->assertDatabaseCount('lti_deployments', 0);
        $this->get(route('admin.tenants.index', ['q' => "' OR 1=1 --"]))->assertOk()->assertDontSee('Private tenant');
        $this->get(route('admin.tenants.index', ['q' => '<script>alert(1)</script>']))->assertOk()->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    public function test_only_admin_can_create_missing_rsa_keys_without_replacing_an_existing_key(): void
    {
        config(['lti.key_directory' => $this->root.'/keys']);
        $tenant = Tenant::factory()->create();
        $url = route('admin.tenants.show', [$tenant, 'tab' => 'connections']);
        $this->post(route('admin.keys.generate'))->assertRedirect(route('account.login'));
        $this->actingAs(User::factory()->create())->post(route('admin.keys.generate'))->assertForbidden();
        $this->assertNull(app(KeyStore::class)->publicKey());
        $this->loginAdmin();
        $this->get($url)->assertOk()->assertSee('Tạo khóa RSA');
        $this->from($url)->post(route('admin.keys.generate'))->assertSessionHasNoErrors()->assertRedirect($url);
        $publicKey = app(KeyStore::class)->publicKey();
        $this->assertNotFalse(openssl_pkey_get_public($publicKey));
        $this->get($url)->assertOk()->assertSee('BEGIN PUBLIC KEY')->assertDontSee('PRIVATE KEY')->assertDontSee('Tạo khóa RSA');
        $this->post(route('admin.keys.generate'))->assertSessionHasNoErrors();
        $this->assertSame($publicKey, app(KeyStore::class)->publicKey());
        $this->assertCount(1, app(KeyStore::class)->jwks()['keys']);
    }

    public function test_admin_assigns_tenant_admin_by_lms_subject_and_changes_member_permissions(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create();
        $deployment = LtiDeployment::factory()->create(['tenant_id' => $tenant->id]);
        $this->post(route('admin.tenants.users.store', $tenant), ['author_deployment' => $deployment->id, 'author_subject' => 'tenant-manager', 'author_role' => 'tenant-admin'])->assertSessionHasNoErrors();
        $identity = LtiIdentity::sole();
        $this->assertDatabaseHas('tenant_identity', ['tenant_id' => $tenant->id, 'lti_identity_id' => $identity->id, 'local_role' => 'tenant-admin']);
        $this->patch(route('admin.tenants.users.role', [$tenant, $identity]), ['local_role' => 'teacher'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_identity', ['tenant_id' => $tenant->id, 'lti_identity_id' => $identity->id, 'local_role' => 'teacher']);
        $this->patch(route('admin.tenants.users.role', [Tenant::factory()->create(), $identity]), ['local_role' => 'tenant-admin'])->assertNotFound();
        $this->patch(route('admin.tenants.users.role', [$tenant, $identity]), ['local_role' => 'lms'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_identity', ['tenant_id' => $tenant->id, 'lti_identity_id' => $identity->id, 'local_role' => null]);
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'connections']))->assertOk()->assertSee('RSA public key');
    }

    public function test_admin_creates_edits_filters_and_locks_tenants(): void
    {
        $this->loginAdmin();
        $data = ['slug' => 'school-one', 'name' => 'School One', 'active' => '1', 'contact_name' => 'Contact', 'contact_email' => 'contact@example.com', 'contact_phone' => '0123456789', 'notes' => 'Notes'];
        $this->get(route('admin.tenants.create'))->assertOk();
        $this->post(route('admin.tenants.store'), $data)->assertSessionHasNoErrors()->assertRedirect();
        $tenant = Tenant::where('slug', 'school-one')->firstOrFail();
        $this->assertDatabaseHas('tenants', $data);
        $this->get(route('admin.tenants.show', $tenant))->assertOk()->assertSee('Contact');
        $this->put(route('admin.tenants.update', $tenant), array_replace($data, ['name' => 'Updated School', 'active' => '0']))->assertSessionHasNoErrors();
        $this->get(route('admin.tenants.index', ['q' => 'Updated', 'active' => '0']))->assertOk()->assertSee('Updated School');
        $this->get(route('admin.tenants.index', ['active' => '1']))->assertDontSee('Updated School');
        $this->post(route('admin.tenants.store'), $data)->assertSessionHasErrors('slug');
    }

    public function test_connections_can_be_managed_but_cannot_be_reassigned(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $data = $this->connectionData();
        $this->post(route('admin.tenants.connections.store', $tenant), $data)->assertSessionHasNoErrors();
        $deployment = LtiDeployment::firstOrFail();
        $this->assertSame($tenant->id, $deployment->tenant_id);
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'connections']))->assertOk()->assertSee('School LMS');
        $this->put(route('admin.tenants.connections.update', [$tenant, $deployment]), array_replace($data, ['active' => '0']))->assertSessionHasNoErrors();
        $this->assertFalse($deployment->fresh()->active);
        $this->post(route('admin.tenants.connections.store', $other), $data)->assertSessionHasErrors('deployment_id');
        $this->put(route('admin.tenants.connections.update', [$other, $deployment]), $data)->assertNotFound();
        $this->put(route('admin.tenants.connections.update', [$tenant, $deployment]), array_replace($data, ['deployment_id' => 'changed']))->assertSessionHasErrors('deployment_id');
        $this->post(route('admin.tenants.connections.store', $tenant), array_replace($data, ['allowed_origins' => 'https://school.example.com/path']))->assertSessionHasErrors('allowed_origins');
        $this->assertDatabaseCount('lti_deployments', 1);
    }

    public function test_member_list_and_filters_only_show_members_of_the_selected_tenant(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create();
        $other = Tenant::factory()->create();
        $member = LtiIdentity::factory()->create(['name' => 'Teacher One', 'email' => 'teacher@example.com']);
        $foreign = LtiIdentity::factory()->create(['name' => 'Private Foreign User', 'lti_platform_id' => $member->lti_platform_id]);
        $role = 'http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor';
        foreach ([[$tenant, $member], [$other, $foreign]] as [$unit, $identity]) {
            DB::table('tenant_identity')->insert(['tenant_id' => $unit->id, 'lti_identity_id' => $identity->id, 'roles' => json_encode([$role]), 'first_access_at' => now(), 'last_access_at' => now()]);
        }
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'users', 'q' => 'Teacher', 'role' => $role]))->assertOk()->assertSee('teacher@example.com')->assertSee($member->subject)->assertDontSee('Private Foreign User');
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'users', 'role' => 'unknown']))->assertOk()->assertDontSee('Teacher One');
    }

    public function test_admin_lists_filters_hides_previews_and_exports_content_without_an_lti_session(): void
    {
        $this->loginAdmin();
        $content = H5pContent::factory()->create(['title' => 'Managed Question']);
        $tenant = Tenant::findOrFail($content->tenant_id);
        $foreign = H5pContent::factory()->create(['title' => 'Foreign Question', 'h5p_library_id' => $content->h5p_library_id, 'lti_identity_id' => $content->lti_identity_id]);
        File::copyDirectory(resource_path('examples/demo-library'), $this->root.'/h5p/libraries/Hub.DemoQuestion-1.0');
        $media = $this->root.'/tenants/'.$tenant->id.'/h5p/content/'.$content->id;
        File::ensureDirectoryExists($media);
        File::put($media.'/example.txt', 'tenant media');
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'contents', 'q' => 'Managed', 'library' => $content->h5p_library_id]))->assertOk()->assertSee('Managed Question')->assertDontSee('Foreign Question');
        $this->patch(route('admin.tenants.visibility', [$tenant, $content]), ['admin_hidden' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('h5p_contents', ['id' => $content->id, 'admin_hidden' => true]);
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'contents', 'status' => 'hidden']))->assertOk()->assertSee('Managed Question');
        $this->get(route('admin.tenants.preview', [$tenant, $content]))->assertOk()->assertSee('H5PIntegration')->assertDontSee('H5PHubResults');
        $this->get(route('admin.tenants.files', [$tenant, $content, 'path' => 'content/'.$content->id.'/example.txt']))->assertOk();
        $this->get(route('admin.tenants.files', [$tenant, $content, 'path' => 'content/'.$foreign->id.'/example.txt']))->assertNotFound();
        $this->get(route('admin.tenants.export', [$tenant, $content]))->assertOk()->assertDownload($content->uuid.'.h5p');
        $this->get(route('admin.tenants.preview', [$tenant, $foreign]))->assertNotFound();
        $this->get(route('admin.tenants.export', [$tenant, $foreign]))->assertNotFound();
        $this->patch(route('admin.tenants.visibility', [$tenant, $foreign]), ['admin_hidden' => '1'])->assertNotFound();
        $this->patch(route('admin.tenants.visibility', [$tenant, $content]), ['admin_hidden' => '0'])->assertSessionHasNoErrors();
    }

    public function test_admin_imports_a_package_for_an_author_in_the_selected_tenant(): void
    {
        $this->loginAdmin();
        $tenant = Tenant::factory()->create();
        $deployment = LtiDeployment::factory()->create(['tenant_id' => $tenant->id]);
        $registration = ['author_deployment' => $deployment->id, 'author_subject' => '42', 'author_name' => 'Teacher for import'];
        $this->post(route('admin.tenants.users.store', Tenant::factory()->create()), $registration)->assertSessionHasErrors('author_deployment');
        $this->post(route('admin.tenants.users.store', $tenant), $registration)->assertSessionHasNoErrors();
        $this->post(route('admin.tenants.users.store', $tenant), $registration)->assertSessionHasNoErrors();
        $author = LtiIdentity::sole();
        $this->assertSame('42', $author->subject);
        $this->assertDatabaseCount('tenant_identity', 1);
        $this->assertDatabaseHas('tenant_identity', ['tenant_id' => $tenant->id, 'lti_identity_id' => $author->id, 'first_access_at' => null, 'last_access_at' => null]);
        $learner = LtiIdentity::factory()->create(['lti_platform_id' => $author->lti_platform_id, 'subject' => 'student-for-import']);
        $library = H5pLibrary::factory()->create();
        DB::table('tenant_identity')->insert([
            'tenant_id' => $tenant->id, 'lti_identity_id' => $learner->id,
            'roles' => json_encode(['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner']),
            'first_access_at' => now(), 'last_access_at' => now(),
        ]);
        $this->post(route('admin.tenants.users.store', $tenant), array_replace($registration, ['author_subject' => $learner->subject]))
            ->assertSessionHasErrors('author_subject');
        $this->get(route('admin.tenants.show', [$tenant, 'tab' => 'users']))
            ->assertOk()->assertSee(route('admin.tenants.contents.import', [$tenant, $author]))
            ->assertDontSee(route('admin.tenants.contents.import', [$tenant, $learner]));
        $this->post(route('admin.tenants.contents.import', [$tenant, $learner]))->assertForbidden();
        $this->post(route('admin.tenants.contents.import', [Tenant::factory()->create(), $author]))->assertNotFound();
        $this->post(route('admin.tenants.contents.import', [$tenant, $author]), [
            'package' => UploadedFile::fake()->createWithContent('broken.h5p', 'invalid archive'),
        ])->assertSessionHasErrors('package');
        $this->assertDatabaseCount('h5p_contents', 0);

        $path = $this->root.'/example.h5p';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::CREATE));
        $zip->addFromString('h5p.json', json_encode([
            'title' => 'Imported question', 'mainLibrary' => $library->machine_name,
            'preloadedDependencies' => [['machineName' => $library->machine_name, 'majorVersion' => 1, 'minorVersion' => 0]],
        ]));
        $zip->addFromString('content/content.json', json_encode(['question' => 'Imported?', 'answer' => true]));
        $zip->close();
        $response = $this->post(route('admin.tenants.contents.import', [$tenant, $author]), [
            'package' => new UploadedFile($path, 'example.h5p', test: true), 'publish' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.tenants.show', [$tenant, 'tab' => 'contents', 'user' => $author->id]));
        $content = H5pContent::sole();
        $this->assertDatabaseHas('h5p_contents', ['id' => $content->id, 'tenant_id' => $tenant->id,
            'lti_identity_id' => $author->id, 'h5p_library_id' => $library->id, 'status' => 'published']);
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Imported question')
            ->assertSee(route('lti.launch', ['content_id' => $content->uuid]));
    }

    public function test_admin_routes_require_admin_for_reads_and_writes(): void
    {
        $tenant = Tenant::factory()->create();
        $content = H5pContent::factory()->create(['tenant_id' => $tenant->id]);
        $this->get(route('admin.tenants.index'))->assertRedirect(route('account.login'));
        $this->post(route('admin.tenants.store'), [])->assertRedirect(route('account.login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.tenants.show', $tenant))->assertForbidden();
        $this->post(route('admin.tenants.connections.store', $tenant), $this->connectionData())->assertForbidden();
        $this->put(route('admin.tenants.update', $tenant), [])->assertForbidden();
        $this->post(route('admin.tenants.contents.import', [$tenant, $content->lti_identity_id]))->assertForbidden();
        $this->post(route('admin.tenants.users.store', $tenant))->assertForbidden();
        $this->get(route('admin.tenants.preview', [$tenant, $content]))->assertForbidden();
        $this->get(route('admin.tenants.export', [$tenant, $content]))->assertForbidden();
        $this->get(route('admin.tenants.files', [$tenant, $content, 'path' => 'content/'.$content->id.'/a.txt']))->assertForbidden();
        $this->patch(route('admin.tenants.visibility', [$tenant, $content]), ['admin_hidden' => '1'])->assertForbidden();
    }
}
