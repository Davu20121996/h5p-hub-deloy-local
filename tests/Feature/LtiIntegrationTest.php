<?php

namespace Tests\Feature;

use App\H5p\LibraryInstaller;
use App\H5p\Runtime;
use App\Lti\Claims;
use App\Lti\KeyStore;
use App\Lti\ScoreService;
use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\LtiLogin;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use App\Models\User;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use ZipArchive;

class LtiIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private LtiPlatform $platform;

    private LtiDeployment $deployment;

    private string $privateKey = '';

    private array $jwk;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/'.Str::uuid());
        File::ensureDirectoryExists($this->root);
        config(['lti.storage_directory' => $this->root, 'lti.key_directory' => $this->root.'/keys']);
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $this->privateKey);
        $details = openssl_pkey_get_details($key);
        $this->jwk = ['kty' => 'RSA', 'kid' => 'platform-key', 'alg' => 'RS256', 'use' => 'sig',
            'n' => JWT::urlsafeB64Encode($details['rsa']['n']), 'e' => JWT::urlsafeB64Encode($details['rsa']['e'])];
        $this->platform = LtiPlatform::factory()->create();
        $this->deployment = LtiDeployment::factory()->create(['lti_platform_id' => $this->platform->id, 'deployment_id' => 'deployment-a']);
        Http::preventStrayRequests();
        Http::fake([$this->platform->jwks_url => Http::response(['keys' => [$this->jwk]])]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    private function login(): array
    {
        $response = $this->get(route('lti.login', [
            'iss' => $this->platform->issuer, 'client_id' => $this->platform->client_id,
            'login_hint' => 'trainer', 'target_link_uri' => route('lti.launch'),
        ]))->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function claims(array $login, array $changes = []): array
    {
        return array_replace([
            'iss' => $this->platform->issuer, 'aud' => $this->platform->client_id, 'sub' => 'user-123',
            'iat' => time(), 'exp' => time() + 300, 'nonce' => $login['nonce'], 'name' => 'Trainer A',
            Claims::PREFIX.'version' => '1.3.0', Claims::PREFIX.'deployment_id' => 'deployment-a',
            Claims::PREFIX.'target_link_uri' => route('lti.launch'),
            Claims::PREFIX.'message_type' => 'LtiDeepLinkingRequest',
            Claims::PREFIX.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Instructor'],
            Claims::DEEP.'deep_linking_settings' => [
                'deep_link_return_url' => 'https://web-a.example.com/return',
                'accept_types' => ['ltiResourceLink'], 'accept_presentation_document_targets' => ['iframe', 'window'],
                'data' => 'opaque platform data',
            ],
        ], $changes);
    }

    private function launch(array $changes = []): TestResponse
    {
        $login = $this->login();
        $token = JWT::encode($this->claims($login, $changes), $this->privateKey, 'RS256', 'platform-key');

        return $this->post(route('lti.launch'), ['state' => $login['state'], 'id_token' => $token]);
    }

    private function activeLaunch(): LtiLaunch
    {
        $this->launch()->assertRedirect();

        return LtiLaunch::latest('created_at')->firstOrFail();
    }

    private function content(?LtiLaunch $launch = null, array $changes = []): H5pContent
    {
        return H5pContent::factory()->create($changes + [
            'tenant_id' => $this->deployment->tenant_id,
            'lti_identity_id' => $launch?->lti_identity_id ?? LtiIdentity::factory()->create(['lti_platform_id' => $this->platform->id])->id,
        ]);
    }

    public function test_managed_client_launch_uses_signed_roles_and_local_restrictions(): void
    {
        config(['lti.managed_client_url' => $this->platform->issuer]);
        $this->launch([Claims::PREFIX.'roles' => [Claims::ADMINISTRATOR]])->assertRedirect();
        $launch = LtiLaunch::sole();
        $this->assertTrue($launch->canManageTenant());
        $this->get(route('workspace.tenant', $launch))->assertOk();
        DB::table('tenant_identity')->where('lti_identity_id', $launch->lti_identity_id)->update(['local_role' => 'tenant-admin']);
        $this->launch([Claims::PREFIX.'roles' => [Claims::LEARNER]])->assertForbidden();
        $this->launch()->assertRedirect();
        $teacher = LtiLaunch::where('id', '!=', $launch->id)->sole();
        $this->assertFalse($teacher->canManageTenant());
        DB::table('tenant_identity')->where('lti_identity_id', $launch->lti_identity_id)->update(['local_role' => 'learner']);
        $this->launch([Claims::PREFIX.'roles' => [Claims::ADMINISTRATOR]])->assertForbidden();
    }

    public function test_tenant_admin_manages_only_members_of_their_tenant_and_reads_public_key(): void
    {
        $launch = $this->activeLaunch();
        $membership = DB::table('tenant_identity')->where('tenant_id', $this->deployment->tenant_id)->where('lti_identity_id', $launch->lti_identity_id);
        $this->get(route('workspace.tenant', $launch))->assertForbidden();
        $membership->update(['local_role' => 'tenant-admin']);
        app(KeyStore::class)->generate();
        $this->get(route('workspace.tenant', $launch))->assertOk()->assertSee('BEGIN PUBLIC KEY')->assertDontSee('PRIVATE KEY');
        $pem = app(KeyStore::class)->publicKey();
        $token = app(KeyStore::class)->sign(['sub' => 'public-key-test']);
        $this->assertSame('public-key-test', JWT::decode($token, new Key($pem, 'RS256'))->sub);
        $teacher = LtiIdentity::factory()->create(['lti_platform_id' => $this->platform->id]);
        DB::table('tenant_identity')->insert(['tenant_id' => $this->deployment->tenant_id, 'lti_identity_id' => $teacher->id, 'roles' => '[]']);
        $this->patch(route('workspace.tenant.role', [$launch, $teacher]), ['local_role' => 'teacher'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('tenant_identity', ['lti_identity_id' => $teacher->id, 'local_role' => 'teacher']);
        $this->patch(route('workspace.tenant.role', [$launch, $teacher]), ['local_role' => 'tenant-admin'])->assertSessionHasErrors('local_role');
        $this->patch(route('workspace.tenant.role', [$launch, $launch->lti_identity_id]), ['local_role' => 'learner'])->assertForbidden();
        $foreign = LtiIdentity::factory()->create(['lti_platform_id' => $this->platform->id]);
        DB::table('tenant_identity')->insert(['tenant_id' => Tenant::factory()->create()->id, 'lti_identity_id' => $foreign->id, 'roles' => '[]']);
        $this->patch(route('workspace.tenant.role', [$launch, $foreign]), ['local_role' => 'teacher'])->assertNotFound();
        $membership->update(['local_role' => 'teacher']);
        $this->get(route('workspace.tenant', $launch))->assertForbidden();
    }

    public function test_learner_result_uses_the_signed_launch_subject_and_line_item(): void
    {
        $tokenUrl = $this->platform->issuer.'/lti/token';
        $lineItem = $this->platform->issuer.'/lti/courses/10/lineitems/20';
        $this->platform->update(['ags_token_url' => $tokenUrl]);
        app(KeyStore::class)->generate();
        Http::fake([
            $tokenUrl => Http::response(['access_token' => 'client-access-token']),
            $lineItem.'/scores' => Http::response(status: 204),
        ]);
        $content = $this->content(changes: ['status' => 'published']);
        $this->launch([
            'sub' => '42',
            Claims::PREFIX.'message_type' => 'LtiResourceLinkRequest',
            Claims::PREFIX.'roles' => [Claims::LEARNER],
            Claims::PREFIX.'resource_link' => ['id' => 'activity-20'],
            Claims::PREFIX.'custom' => ['content_id' => $content->uuid],
            ScoreService::ENDPOINT => ['scope' => [ScoreService::SCOPE], 'lineitem' => $lineItem],
        ])->assertRedirect();
        $launch = LtiLaunch::sole();
        $this->assertSame($lineItem, $launch->ags_lineitem_url);

        $result = ['timestamp' => now()->utc()->format('Y-m-d\TH:i:s.v\Z')];
        $this->postJson(route('content.result', [$launch, $content->uuid]), $result + ['score' => 3, 'maximum' => 2])
            ->assertUnprocessable();
        $this->postJson(route('content.result', [$launch, Str::uuid()]), $result)->assertForbidden();
        $this->postJson(route('content.result', [$launch, $content->uuid]), $result + ['score' => 1, 'maximum' => 2, 'userId' => 'other-user'])
            ->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === $tokenUrl && $request['scope'] === ScoreService::SCOPE);
        [$scoreRequest] = Http::recorded(fn ($request) => $request->url() === $lineItem.'/scores')->sole();
        $this->assertTrue($scoreRequest->hasHeader('Authorization', 'Bearer client-access-token'));
        $this->assertTrue($scoreRequest->hasHeader('Content-Type', 'application/vnd.ims.lis.v1.score+json'));
        $score = json_decode($scoreRequest->body(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('42', $score['userId']);
        $this->assertEquals(1, $score['scoreGiven']);
        $this->assertSame('FullyGraded', $score['gradingProgress']);
    }

    public function test_local_permissions_survive_lms_login_and_revocation_applies_to_existing_sessions(): void
    {
        $launch = $this->activeLaunch();
        $membership = DB::table('tenant_identity')->where('tenant_id', $this->deployment->tenant_id)->where('lti_identity_id', $launch->lti_identity_id);
        $membership->update(['local_role' => 'teacher']);
        $this->launch([Claims::PREFIX.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner']])->assertRedirect();
        $this->assertSame('teacher', $membership->value('local_role'));
        $this->get(route('workspace.index', $launch))->assertOk();
        $membership->update(['local_role' => 'learner']);
        $this->get(route('content.create', $launch))->assertForbidden();
        $this->post(route('content.import', $launch))->assertForbidden();
        $this->launch()->assertForbidden();
        $membership->update(['local_role' => null]);
        $this->get(route('workspace.index', $launch))->assertOk();
    }

    public function test_only_system_admin_installs_libraries_and_teacher_imports_h5p_content(): void
    {
        $launch = $this->activeLaunch();
        DB::table('tenant_identity')->where('lti_identity_id', $launch->lti_identity_id)->update(['local_role' => 'tenant-admin']);
        $path = $this->root.'/library.h5p';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (File::files(resource_path('examples/demo-library')) as $file) {
            $zip->addFile($file->getPathname(), 'Hub.DemoQuestion-1.0/'.$file->getFilename());
        }
        $zip->addFromString('h5p.json', json_encode(['title' => 'Teacher import', 'mainLibrary' => 'Hub.DemoQuestion', 'preloadedDependencies' => [['machineName' => 'Hub.DemoQuestion', 'majorVersion' => 1, 'minorVersion' => 0]]]));
        $zip->addFromString('content/content.json', json_encode(['question' => 'Imported?', 'answer' => true]));
        $zip->close();
        $this->assertDatabaseCount('h5p_libraries', 0);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->post(route('admin.install'), ['package' => new UploadedFile($path, 'library.h5p', test: true)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseCount('h5p_libraries', 1);
        DB::table('tenant_identity')->where('lti_identity_id', $launch->lti_identity_id)->update(['local_role' => 'teacher']);
        $this->post(route('content.import', $launch), ['package' => new UploadedFile($path, 'lesson.h5p', test: true)])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('h5p_contents', ['tenant_id' => $this->deployment->tenant_id, 'lti_identity_id' => $launch->lti_identity_id, 'title' => 'Teacher import']);
        $this->post(route('content.import', $launch), ['package' => UploadedFile::fake()->createWithContent('bad.h5p', 'broken')])->assertSessionHasErrors('package');
    }

    public function test_login_state_is_stored_hashed_and_oidc_parameters_are_correct(): void
    {
        $query = $this->login();
        $this->assertSame('openid', $query['scope']);
        $this->assertSame('form_post', $query['response_mode']);
        $this->assertSame('none', $query['prompt']);
        $this->assertDatabaseHas('lti_logins', ['state_hash' => hash('sha256', $query['state']), 'nonce_hash' => hash('sha256', $query['nonce'])]);
        $this->assertDatabaseMissing('lti_logins', ['state_hash' => $query['state']]);
    }

    public function test_membership_is_provisioned_once_per_tenant_and_keeps_latest_roles_after_launches_are_deleted(): void
    {
        $pending = LtiIdentity::factory()->create(['lti_platform_id' => $this->platform->id, 'subject' => 'user-123']);
        DB::table('tenant_identity')->insert(['tenant_id' => $this->deployment->tenant_id, 'lti_identity_id' => $pending->id, 'roles' => '[]']);
        $launch = $this->activeLaunch();
        $first = DB::table('tenant_identity')->first();
        $this->assertSame($this->deployment->tenant_id, $first->tenant_id);
        $this->assertSame($launch->lti_identity_id, $first->lti_identity_id);
        $this->assertSame($pending->id, $launch->lti_identity_id);
        $this->assertNotNull($first->first_access_at);
        $role = 'http://purl.imsglobal.org/vocab/lis/v2/membership#ContentDeveloper';
        $this->travel(1)->minutes();
        $this->launch([Claims::PREFIX.'roles' => [$role], 'name' => 'Updated Name'])->assertRedirect();
        $latest = DB::table('tenant_identity')->first();
        $this->assertSame($first->first_access_at, $latest->first_access_at);
        $this->assertGreaterThan($first->last_access_at, $latest->last_access_at);
        $this->assertSame([$role], json_decode($latest->roles, true));
        $this->assertDatabaseCount('tenant_identity', 1);
        $this->assertDatabaseCount('lti_identities', 1);
        $other = LtiDeployment::factory()->create(['lti_platform_id' => $this->platform->id]);
        $this->launch([Claims::PREFIX.'deployment_id' => $other->deployment_id])->assertRedirect();
        $this->assertDatabaseHas('tenant_identity', ['tenant_id' => $other->tenant_id, 'lti_identity_id' => $launch->lti_identity_id]);
        LtiLaunch::query()->delete();
        $this->assertDatabaseCount('tenant_identity', 2);
        $this->assertDatabaseHas('lti_identities', ['id' => $launch->lti_identity_id]);
    }

    public function test_admin_hidden_content_is_blocked_for_existing_sessions_and_new_launches(): void
    {
        $launch = $this->activeLaunch();
        $content = $this->content($launch, ['admin_hidden' => true, 'title' => 'Hidden admin content']);
        $this->get(route('workspace.index', $launch))->assertOk()->assertDontSee('Hidden admin content');
        $this->get(route('content.play', ['launch' => $launch, 'uuid' => $content->uuid]))->assertNotFound();
        $this->get(route('content.edit', ['launch' => $launch, 'uuid' => $content->uuid]))->assertNotFound();
        $this->get(route('content.export', ['launch' => $launch, 'uuid' => $content->uuid]))->assertNotFound();
        $this->launch([Claims::PREFIX.'message_type' => 'LtiResourceLinkRequest',
            Claims::PREFIX.'resource_link' => ['id' => 'hidden-link'], Claims::PREFIX.'custom' => ['content_id' => $content->uuid],
        ])->assertForbidden();
        $this->assertDatabaseMissing('lti_resource_links', ['resource_link_id' => 'hidden-link']);
    }

    public function test_locked_tenant_rejects_new_launch_and_existing_workspace_session(): void
    {
        $launch = $this->activeLaunch();
        $this->deployment->tenant->update(['active' => false]);
        $this->get(route('workspace.index', $launch))->assertForbidden();
        $this->launch()->assertForbidden();
        $this->assertDatabaseCount('lti_launches', 1);
    }

    public function test_signed_deep_link_launch_opens_only_its_tenant_workspace(): void
    {
        $launch = $this->activeLaunch();
        $this->assertTrue($launch->can_author);
        $this->assertSame($this->deployment->id, $launch->lti_deployment_id);
        $this->get(route('workspace.index', ['launch' => $launch->id]))->assertOk()->assertSee('Kho bài H5P');
    }

    public function test_replayed_launch_is_rejected(): void
    {
        $login = $this->login();
        $payload = ['state' => $login['state'], 'id_token' => JWT::encode($this->claims($login), $this->privateKey, 'RS256', 'platform-key')];
        $this->post(route('lti.launch'), $payload)->assertRedirect();
        $this->post(route('lti.launch'), $payload)->assertUnauthorized();
        $this->assertDatabaseCount('lti_launches', 1);
    }

    public function test_expired_login_state_is_rejected(): void
    {
        $login = $this->login();
        LtiLogin::query()->update(['expires_at' => now()->subMinute()]);
        $this->post(route('lti.launch'), ['state' => $login['state'], 'id_token' => JWT::encode($this->claims($login), $this->privateKey, 'RS256', 'platform-key')])->assertUnauthorized();
    }

    public function test_a_valid_launch_cannot_be_relayed_to_a_different_browser_session(): void
    {
        $login = $this->login();
        $token = JWT::encode($this->claims($login), $this->privateKey, 'RS256', 'platform-key');
        $this->flushSession();
        $this->post(route('lti.launch'), ['state' => $login['state'], 'id_token' => $token])->assertUnauthorized();
        $this->assertDatabaseCount('lti_launches', 0);
    }

    public function test_invalid_audience_nonce_expiry_and_target_are_rejected(): void
    {
        foreach ([
            ['aud' => 'other-client'], ['nonce' => 'wrong'], ['exp' => time() - 10],
            [Claims::PREFIX.'target_link_uri' => 'https://evil.example/launch'],
            ['aud' => [$this->platform->client_id, 'other'], 'azp' => 'other'],
            [Claims::PREFIX.'version' => '1.1.0'],
        ] as $changes) {
            $this->launch($changes)->assertUnauthorized();
        }
        $this->assertDatabaseCount('lti_launches', 0);
    }

    public function test_signature_from_another_key_is_rejected(): void
    {
        $login = $this->login();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $private);
        $this->post(route('lti.launch'), ['state' => $login['state'], 'id_token' => JWT::encode($this->claims($login), $private, 'RS256', 'platform-key')])->assertUnauthorized();
    }

    public function test_learner_and_unmapped_admin_cannot_open_deep_linking(): void
    {
        foreach (['Learner', 'Administrator'] as $role) {
            $this->launch([Claims::PREFIX.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#'.$role]])->assertForbidden();
        }
    }

    public function test_untrusted_return_origin_and_login_target_are_rejected(): void
    {
        $this->launch([Claims::DEEP.'deep_linking_settings' => [
            'deep_link_return_url' => 'https://evil.example/return', 'accept_types' => ['ltiResourceLink'],
            'accept_presentation_document_targets' => ['iframe'],
        ]])->assertBadRequest();
        $this->get(route('lti.login', ['iss' => $this->platform->issuer, 'login_hint' => 'x', 'target_link_uri' => 'https://evil.example/lti/launch']))->assertBadRequest();
    }

    public function test_disabled_deployment_or_tenant_blocks_launch(): void
    {
        $this->deployment->update(['active' => false]);
        $this->launch()->assertForbidden();
        $this->deployment->update(['active' => true]);
        $this->deployment->tenant->update(['active' => false]);
        $this->launch()->assertForbidden();
    }

    public function test_deep_link_response_is_signed_echoes_data_and_has_no_ags(): void
    {
        app(KeyStore::class)->generate();
        $launch = $this->activeLaunch();
        $content = $this->content($launch);
        $response = $this->post(route('lti.complete', ['launch' => $launch->id]), ['content_id' => $content->uuid])->assertOk();
        preg_match('/name="JWT" value="([^"]+)"/', $response->getContent(), $matches);
        $claims = json_decode(json_encode(JWT::decode(html_entity_decode($matches[1]), JWK::parseKeySet(app(KeyStore::class)->jwks(), 'RS256'))), true);
        $this->assertSame($this->platform->client_id, $claims['iss']);
        $this->assertSame('LtiDeepLinkingResponse', $claims[Claims::PREFIX.'message_type']);
        $this->assertSame('opaque platform data', $claims[Claims::DEEP.'data']);
        $item = $claims[Claims::DEEP.'content_items'][0];
        $this->assertSame($content->uuid, $item['custom']['content_id']);
        $this->assertArrayNotHasKey('lineItem', $item);
        $this->post(route('lti.complete', ['launch' => $launch->id]), ['content_id' => $content->uuid])->assertForbidden();
    }

    public function test_deep_link_cannot_select_another_tenant_or_draft_content(): void
    {
        app(KeyStore::class)->generate();
        $launch = $this->activeLaunch();
        $foreign = $this->content($launch, ['tenant_id' => Tenant::factory()->create()->id]);
        $this->post(route('lti.complete', ['launch' => $launch->id]), ['content_id' => $foreign->uuid])->assertNotFound();
        $draft = $this->content($launch, ['status' => 'draft', 'h5p_library_id' => $foreign->h5p_library_id]);
        $this->post(route('lti.complete', ['launch' => $launch->id]), ['content_id' => $draft->uuid])->assertUnprocessable();
    }

    public function test_learner_resource_launch_binds_content_without_saving_results(): void
    {
        $content = $this->content();
        $this->launch([
            Claims::PREFIX.'message_type' => 'LtiResourceLinkRequest',
            Claims::PREFIX.'roles' => ['http://purl.imsglobal.org/vocab/lis/v2/membership#Learner'],
            Claims::PREFIX.'resource_link' => ['id' => 'lesson-1'],
            Claims::PREFIX.'custom' => ['content_id' => $content->uuid],
        ])->assertRedirect();
        $launch = LtiLaunch::firstOrFail();
        $this->get(route('content.create', ['launch' => $launch->id]))->assertForbidden();
        $this->assertDatabaseHas('lti_resource_links', ['resource_link_id' => 'lesson-1', 'h5p_content_id' => $content->id]);
        $other = $this->content(null, ['h5p_library_id' => $content->h5p_library_id]);
        $this->get(route('content.play', ['launch' => $launch->id, 'uuid' => $other->uuid]))->assertForbidden();
    }

    public function test_missing_browser_session_cannot_open_launch(): void
    {
        $launch = $this->activeLaunch();
        $this->flushSession();
        $this->get(route('workspace.index', ['launch' => $launch->id]))->assertUnauthorized();
    }

    public function test_trainer_cannot_edit_another_authors_content(): void
    {
        $launch = $this->activeLaunch();
        $content = $this->content();
        $this->get(route('content.edit', ['launch' => $launch->id, 'uuid' => $content->uuid]))->assertForbidden();
    }

    public function test_editor_player_and_semantics_endpoints_render(): void
    {
        $launch = $this->activeLaunch();
        $this->get(route('content.create', $launch))->assertOk()->assertSee('HUB chưa có loại bài H5P để soạn');
        $library = H5pLibrary::factory()->create();
        File::copyDirectory(resource_path('examples/demo-library'), Runtime::libraryPath().'/Hub.DemoQuestion-1.0');
        $this->get(route('workspace.index', $launch))->assertOk()->assertSee('Tạo bài H5P')->assertSee('Nhập bài .h5p');
        $this->get(route('content.create', $launch))->assertOk()->assertSee('Tạo bài H5P')->assertSee('h5p-editor');
        $this->getJson(route('h5p.ajax', ['launch' => $launch->id, 'action' => 'libraries']))->assertOk()->assertJsonPath('0.name', 'Hub.DemoQuestion');
        $this->getJson(route('h5p.ajax', ['launch' => $launch->id, 'action' => 'libraries', 'machineName' => 'Hub.DemoQuestion', 'majorVersion' => 1, 'minorVersion' => 0]))->assertOk()->assertJsonPath('name', 'Hub.DemoQuestion');
        $form = [
            'title' => 'Bài giảng mới', 'library' => 'Hub.DemoQuestion 1.0', 'status' => 'draft',
            'parameters' => json_encode(['params' => ['question' => 'Nội dung câu hỏi?', 'answer' => true], 'metadata' => ['license' => 'U']]),
        ];
        $created = $this->post(route('content.store', $launch), $form)->assertRedirect();
        $content = H5pContent::sole();
        $this->assertSame($library->id, $content->h5p_library_id);
        $editUrl = route('content.edit', [$launch, $content->uuid]);
        $created->assertRedirect($editUrl);
        $this->get($editUrl)->assertOk()->assertSee('Sửa bài H5P')->assertDontSee('Gắn bài đã lưu vào khóa học');
        $this->post(route('content.update', [$launch, $content->uuid]), array_replace($form, [
            'title' => 'Bài giảng đã sửa', 'status' => 'published',
            'parameters' => json_encode(['params' => ['question' => 'Câu hỏi đã sửa?', 'answer' => true], 'metadata' => ['license' => 'U']]),
        ]))->assertRedirect($editUrl);
        $this->assertSame('Bài giảng đã sửa', $content->fresh()->title);
        $this->assertSame('Câu hỏi đã sửa?', $content->fresh()->parameters['question']);
        $this->get($editUrl)->assertOk()->assertSee('Gắn bài đã lưu vào khóa học');
        $this->get(route('content.play', [$launch, $content->uuid]))->assertOk()->assertSee('h5p-content', false)->assertSee($editUrl);

        $resource = $this->launch([
            Claims::PREFIX.'message_type' => 'LtiResourceLinkRequest',
            Claims::PREFIX.'resource_link' => ['id' => 'editor-lesson'],
            Claims::PREFIX.'custom' => ['content_id' => $content->uuid],
        ])->assertRedirect();
        $resourceLaunch = LtiLaunch::where('message_type', 'LtiResourceLinkRequest')->sole();
        $resource->assertRedirect(route('content.play', [$resourceLaunch, $content->uuid]));
    }

    public function test_trainer_can_save_filtered_content_and_export_import_package(): void
    {
        $launch = $this->activeLaunch();
        $library = H5pLibrary::factory()->create();
        File::copyDirectory(resource_path('examples/demo-library'), Runtime::libraryPath().'/Hub.DemoQuestion-1.0');
        $runtime = new Runtime($launch);
        $content = $runtime->save(null, 'Hub.DemoQuestion 1.0', ['question' => 'Test?', 'answer' => true], ['license' => 'U'], 'Test', 'published');
        $this->assertNotNull($content->filtered);
        $package = $runtime->export($content);
        $imported = $runtime->import($package);
        $this->assertSame($content->tenant_id, $imported->tenant_id);
        $this->assertSame('draft', $imported->status);
        $this->assertSame($content->parameters, $imported->parameters);
    }

    public function test_content_files_require_the_correct_tenant_and_launch(): void
    {
        $launch = $this->activeLaunch();
        $content = $this->content($launch);
        $runtime = new Runtime($launch);
        File::ensureDirectoryExists($runtime->tenantPath().'/content/'.$content->id);
        File::put($runtime->tenantPath().'/content/'.$content->id.'/private.txt', 'private data');
        $this->get(route('h5p.files', ['launch' => $launch->id, 'path' => 'content/'.$content->id.'/private.txt']))->assertOk();
        $foreign = $this->content($launch, ['tenant_id' => Tenant::factory()->create()->id, 'h5p_library_id' => $content->h5p_library_id]);
        $this->get(route('h5p.files', ['launch' => $launch->id, 'path' => 'content/'.$foreign->id.'/private.txt']))->assertNotFound();
    }

    public function test_package_traversal_and_size_limit_are_rejected(): void
    {
        $path = $this->root.'/bad.h5p';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('../escape.php', 'bad');
        $zip->close();
        try {
            Runtime::openPackage($path);
            $this->fail('Traversal was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        File::delete($path);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('content/content.json', str_repeat('a', 1000));
        $zip->close();
        config(['lti.max_extracted_bytes' => 100]);
        $this->expectException(HttpException::class);
        Runtime::openPackage($path);
    }

    public function test_library_install_is_central_and_updates_only_newer_patches(): void
    {
        $path = $this->root.'/demo.h5p';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        foreach (File::files(resource_path('examples/demo-library')) as $file) {
            $zip->addFile($file->getPathname(), 'Hub.DemoQuestion-1.0/'.$file->getFilename());
        }
        $zip->close();
        $this->assertSame(1, app(LibraryInstaller::class)->install($path));
        $this->assertSame(0, app(LibraryInstaller::class)->install($path));
        $this->assertDatabaseHas('h5p_libraries', ['machine_name' => 'Hub.DemoQuestion']);
    }
}
