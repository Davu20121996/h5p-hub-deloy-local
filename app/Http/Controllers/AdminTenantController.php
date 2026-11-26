<?php

namespace App\Http\Controllers;

use App\H5p\Runtime;
use App\Lti\Claims;
use App\Lti\KeyStore;
use App\Lti\ScoreService;
use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AdminTenantController extends Controller
{
    public function index(Request $request): View
    {
        $request->validate(['q' => 'nullable|string|max:255', 'active' => 'nullable|in:0,1']);
        $tenants = Tenant::query()->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', '%'.$request->query('q').'%')->orWhere('slug', 'like', '%'.$request->query('q').'%')))
            ->when($request->filled('active'), fn ($query) => $query->where('active', $request->query('active')))
            ->orderBy('name')->paginate(20)->withQueryString();

        return view('admin.tenants', compact('tenants'));
    }

    public function create(): View
    {
        return view('admin.tenant-form', ['tenant' => new Tenant(['active' => true])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = Tenant::create($this->tenantData($request));

        return redirect()->route('admin.tenants.show', $tenant)->with('status', 'Đã tạo đơn vị.');
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $tenant->update($this->tenantData($request, $tenant));

        return back()->with('status', 'Đã cập nhật đơn vị.');
    }

    public function clientCredentials(Request $request, Tenant $tenant, KeyStore $keys): Response
    {
        abort_unless($tenant->active, 403);
        $data = $request->validate([
            'manager_name' => $this->plainTextRules(),
            'manager_email' => 'required|email|max:255',
            'deployment' => 'required|integer',
        ]);

        $deployment = LtiDeployment::with('platform')->where('tenant_id', $tenant->id)
            ->where('active', true)->find($data['deployment']);
        $clientUrl = (string) config('lti.managed_client_url');
        $secret = (string) config('lti.provisioning_secret');
        $publicKey = $keys->publicKey();
        if (! $deployment?->platform->active || ! Claims::isManagedClient($deployment->platform->issuer)
            || ! $publicKey || strlen($secret) < 32) {
            throw ValidationException::withMessages(['deployment' => 'Kiểm tra kết nối LTI của Client, khóa ký HUB và cấu hình cấp tài khoản.']);
        }

        $email = Str::lower($data['manager_email']);
        $sessionKey = 'pending_client_password.'.hash('sha256', $tenant->id.'|'.$deployment->id.'|'.$email);
        $pending = $request->session()->get($sessionKey);
        $password = $pending && $pending['expires_at'] > time()
            ? Crypt::decryptString($pending['password']) : Str::password(24);
        $request->session()->put($sessionKey, [
            'password' => Crypt::encryptString($password), 'expires_at' => time() + 600,
        ]);
        try {
            $result = Http::acceptJson()->withToken($secret)->withoutRedirecting()->connectTimeout(3)->timeout(8)
                ->post(rtrim($clientUrl, '/').'/hub/provision', [
                    'hub_tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'manager_name' => $data['manager_name'],
                    'manager_email' => $email,
                    'password' => $password,
                    'client_id' => $deployment->platform->client_id,
                    'deployment_id' => $deployment->deployment_id,
                    'login_url' => rtrim(config('app.url'), '/').route('lti.login', absolute: false),
                    'launch_url' => rtrim(config('app.url'), '/').route('lti.launch', absolute: false),
                    'public_key' => $publicKey,
                ]);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['deployment' => 'HUB chưa nhận được phản hồi từ Client. Kiểm tra kết nối rồi thử lại.']);
        }

        $passwordIssued = $result->json('password_issued');
        if (! $result->successful() || ! is_bool($passwordIssued)) {
            $message = $result->conflict()
                ? 'Client báo trùng tổ chức, email hoặc kết nối LTI. Hãy kiểm tra thông tin đã đăng ký.'
                : 'Client chưa xác nhận cấp tài khoản. Hãy kiểm tra cấu hình kết nối.';
            throw ValidationException::withMessages(['deployment' => $message]);
        }

        DB::table('lti_platforms')->where('id', $deployment->lti_platform_id)->whereNull('ags_token_url')
            ->update(['ags_token_url' => rtrim($clientUrl, '/').'/lti/token']);
        $request->session()->forget($sessionKey);

        return response()->view('admin.tenant-client-credentials', [
            'tenant' => $tenant,
            'name' => $data['manager_name'],
            'email' => $email,
            'clientUrl' => $clientUrl,
            'password' => $passwordIssued ? $password : null,
        ])->header('Cache-Control', 'no-store, private');
    }

    /** @return array<string, mixed> */
    private function tenantData(Request $request, ?Tenant $tenant = null): array
    {
        return $request->validate([
            'slug' => ['required', 'alpha_dash:ascii', 'max:100', Rule::unique('tenants')->ignore($tenant)],
            'name' => $this->plainTextRules(), 'contact_name' => $this->plainTextRules(false),
            'contact_email' => 'nullable|email|max:255', 'contact_phone' => ['nullable', 'string', 'max:50', 'regex:/\A[0-9+().\s-]+\z/'],
            'notes' => $this->plainTextRules(false, 10000), 'active' => 'required|boolean',
        ]);
    }

    public function show(Request $request, Tenant $tenant): View
    {
        $request->validate(['q' => 'nullable|string|max:255', 'role' => 'nullable|string|max:255', 'status' => 'nullable|in:draft,published,hidden', 'library' => 'nullable|integer', 'user' => 'nullable|integer', 'tab' => 'nullable|in:profile,connections,users,contents']);
        $deployments = LtiDeployment::with('platform')->where('tenant_id', $tenant->id)->get();
        $members = DB::table('tenant_identity')->join('lti_identities', 'lti_identities.id', '=', 'tenant_identity.lti_identity_id')
            ->join('lti_platforms', 'lti_platforms.id', '=', 'lti_identities.lti_platform_id')->where('tenant_id', $tenant->id)
            ->when($request->filled('q'), fn ($query) => $query->where(fn ($query) => $query->where('lti_identities.name', 'like', '%'.$request->query('q').'%')->orWhere('email', 'like', '%'.$request->query('q').'%')->orWhere('subject', 'like', '%'.$request->query('q').'%')))
            ->when($request->filled('role'), fn ($query) => $query->whereJsonContains('roles', $request->query('role')))
            ->when($request->filled('user'), fn ($query) => $query->where('lti_identity_id', $request->query('user')))
            ->select('tenant_identity.*', 'lti_identities.name', 'email', 'subject', 'lti_platforms.name as platform_name', 'lti_platforms.issuer as platform_issuer')
            ->orderByDesc('last_access_at')->paginate(20, ['*'], 'users_page')->withQueryString();
        $contents = H5pContent::with('library')->where('tenant_id', $tenant->id)
            ->when($request->filled('q'), fn ($query) => $query->where('title', 'like', '%'.$request->query('q').'%'))
            ->when($request->query('status') === 'hidden', fn ($query) => $query->where('admin_hidden', true))
            ->when(in_array($request->query('status'), ['draft', 'published'], true), fn ($query) => $query->where('status', $request->query('status'))->where('admin_hidden', false))
            ->when($request->filled('library'), fn ($query) => $query->where('h5p_library_id', $request->query('library')))
            ->when($request->filled('user'), fn ($query) => $query->where('lti_identity_id', $request->query('user')))
            ->latest('updated_at')->paginate(15, ['*'], 'contents_page')->withQueryString();
        $authors = LtiIdentity::whereIn('id', $contents->pluck('lti_identity_id'))->get()->keyBy('id');
        $sizes = $contents->mapWithKeys(function (H5pContent $content): array {
            $path = config('lti.storage_directory').'/tenants/'.$content->tenant_id.'/h5p/content/'.$content->id;

            return [$content->id => strlen(json_encode($content->parameters)) + (is_dir($path) ? collect(File::allFiles($path))->sum(fn ($file) => $file->getSize()) : 0)];
        });
        $roleOptions = DB::table('tenant_identity')->where('tenant_id', $tenant->id)->pluck('roles')->flatMap(fn ($roles) => json_decode($roles, true) ?? [])->unique()->sort();

        return view('admin.tenant', compact('tenant', 'deployments', 'members', 'contents', 'authors', 'sizes', 'roleOptions') + ['libraries' => H5pLibrary::orderBy('machine_name')->get()]);
    }

    public function connection(Request $request, Tenant $tenant, ?LtiDeployment $deployment = null): RedirectResponse
    {
        if ($deployment) {
            abort_unless($deployment->tenant_id === $tenant->id, 404);
        }
        $data = $request->validate([
            'name' => $this->plainTextRules(), 'issuer' => 'required|url|max:512', 'client_id' => $this->plainTextRules(),
            'authorization_url' => 'required|url|max:2048', 'jwks_url' => 'required|url|max:2048',
            'ags_token_url' => 'sometimes|nullable|url|max:2048',
            'deployment_id' => $this->plainTextRules(), 'allowed_origins' => 'required|string|max:10000',
            'platform_active' => 'required|boolean', 'active' => 'required|boolean',
        ]);
        foreach (['issuer', 'authorization_url', 'jwks_url'] as $key) {
            if (! Claims::validUrl($data[$key])) {
                throw ValidationException::withMessages([$key => 'URL phải dùng HTTPS; HTTP chỉ dùng khi bật LTI_ALLOW_HTTP.']);
            }
        }
        if (isset($data['ags_token_url']) && ! ScoreService::isPlatformUrl($data['ags_token_url'], $data['issuer'])) {
            throw ValidationException::withMessages(['ags_token_url' => 'URL cấp token AGS phải cùng origin với Issuer và không chứa query hoặc fragment.']);
        }
        $origins = array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', $data['allowed_origins'])))));
        foreach ($origins as $origin) {
            if (! Claims::validUrl($origin) || rtrim($origin, '/') !== Claims::origin($origin)) {
                throw ValidationException::withMessages(['allowed_origins' => 'Mỗi dòng là một origin chính xác, không có đường dẫn hoặc wildcard.']);
            }
        }
        if ($origins === []) {
            throw ValidationException::withMessages(['allowed_origins' => 'Cần ít nhất một origin.']);
        }
        DB::transaction(function () use ($data, $tenant, $deployment, $origins): void {
            $platform = LtiPlatform::firstOrCreate(['issuer' => $data['issuer'], 'client_id' => $data['client_id']], [
                'name' => $data['name'], 'authorization_url' => $data['authorization_url'], 'jwks_url' => $data['jwks_url'], 'allowed_origins' => $origins, 'active' => $data['platform_active'],
            ]);
            if ($deployment && ($platform->id !== $deployment->lti_platform_id || $data['deployment_id'] !== $deployment->deployment_id)) {
                throw ValidationException::withMessages(['deployment_id' => 'Không đổi định danh kết nối hiện có. Hãy thêm kết nối mới.']);
            }
            $existing = LtiDeployment::where('lti_platform_id', $platform->id)->where('deployment_id', $data['deployment_id'])->first();
            if ($existing && $existing->tenant_id !== $tenant->id) {
                throw ValidationException::withMessages(['deployment_id' => 'Deployment đã thuộc đơn vị khác.']);
            }
            $platform->update(['name' => $data['name'], 'authorization_url' => $data['authorization_url'], 'jwks_url' => $data['jwks_url'], 'allowed_origins' => $origins, 'active' => $data['platform_active']]
                + array_intersect_key($data, ['ags_token_url' => true]));
            LtiDeployment::updateOrCreate(['lti_platform_id' => $platform->id, 'deployment_id' => $data['deployment_id']], ['tenant_id' => $tenant->id, 'active' => $data['active']]);
        });

        return back()->with('status', 'Đã lưu kết nối LTI.');
    }

    public function addAuthor(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'author_deployment' => 'required|integer',
            'author_subject' => $this->plainTextRules(),
            'author_name' => $this->plainTextRules(false),
            'author_role' => 'sometimes|required|in:teacher,tenant-admin',
        ]);
        $deployment = LtiDeployment::where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('platform', fn ($query) => $query->where('active', true))->find($data['author_deployment']);
        if (! $tenant->active || ! $deployment) {
            throw ValidationException::withMessages(['author_deployment' => 'Chọn kết nối LTI đang hoạt động của đơn vị này.']);
        }

        $identity = DB::transaction(function () use ($tenant, $deployment, $data): LtiIdentity {
            $identity = LtiIdentity::firstOrCreate([
                'lti_platform_id' => $deployment->lti_platform_id,
                'subject' => $data['author_subject'],
            ], ['name' => $data['author_name'] ?? null]);
            $key = ['tenant_id' => $tenant->id, 'lti_identity_id' => $identity->id];
            DB::table('tenant_identity')->insertOrIgnore($key + ['roles' => '[]']);
            $member = DB::table('tenant_identity')->where($key)->first();
            if (! isset($data['author_role']) && $member->first_access_at !== null && ! Claims::canAuthor(json_decode($member->roles, true) ?? [])) {
                throw ValidationException::withMessages(['author_subject' => 'Tài khoản đã đồng bộ từ LMS với vai trò không có quyền soạn bài.']);
            }

            if (isset($data['author_role'])) {
                DB::table('tenant_identity')->where($key)->update(['local_role' => $data['author_role']]);
            }

            return $identity;
        });

        return redirect()->route('admin.tenants.show', [$tenant, 'tab' => 'users', 'user' => $identity->id])
            ->with('status', 'Đã thêm định danh giáo viên. Bạn có thể nhập bài cho người dùng này.');
    }

    public function role(Request $request, Tenant $tenant, LtiIdentity $identity): RedirectResponse
    {
        $data = $request->validate(['local_role' => 'required|in:lms,teacher,tenant-admin,learner']);
        $membership = DB::table('tenant_identity')->where('tenant_id', $tenant->id)->where('lti_identity_id', $identity->id);
        abort_unless($membership->exists(), 404);
        $membership->update(['local_role' => $data['local_role'] === 'lms' ? null : $data['local_role']]);

        return back()->with('status', 'Đã cập nhật quyền người dùng trong đơn vị.');
    }

    public function import(Request $request, Tenant $tenant, LtiIdentity $identity): RedirectResponse
    {
        $member = DB::table('tenant_identity')->where('tenant_id', $tenant->id)
            ->where('lti_identity_id', $identity->id)->first();
        abort_unless($member, 404);
        abort_unless($member->local_role === null ? ($member->first_access_at === null || Claims::canAuthor(json_decode($member->roles, true) ?? [])) : in_array($member->local_role, ['teacher', 'tenant-admin'], true), 403);

        $data = $request->validate([
            'package' => 'required|file|max:51200|extensions:h5p',
            'publish' => 'sometimes|boolean',
        ]);
        $owner = new H5pContent(['tenant_id' => $tenant->id, 'lti_identity_id' => $identity->id]);
        try {
            $content = $this->runtime($tenant, $owner)->import($data['package']->getRealPath());
        } catch (JsonException) {
            throw ValidationException::withMessages(['package' => 'Dữ liệu JSON trong gói H5P không hợp lệ.']);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 422) {
                throw $exception;
            }

            throw ValidationException::withMessages(['package' => $exception->getMessage() ?: 'Gói H5P không hợp lệ.']);
        }
        if ($request->boolean('publish')) {
            $content->update(['status' => 'published']);
        }

        return redirect()->route('admin.tenants.show', [$tenant, 'tab' => 'contents', 'user' => $identity->id])
            ->with('status', 'Đã nhập bài H5P cho người dùng.');
    }

    public function visibility(Request $request, Tenant $tenant, H5pContent $content): RedirectResponse
    {
        abort_unless($content->tenant_id === $tenant->id, 404);
        $content->update($request->validate(['admin_hidden' => 'required|boolean']));

        return back()->with('status', 'Đã cập nhật hiển thị bài.');
    }

    private function runtime(Tenant $tenant, H5pContent $content): Runtime
    {
        abort_unless($content->tenant_id === $tenant->id, 404);
        $deployment = new LtiDeployment(['tenant_id' => $tenant->id]);
        $deployment->setRelation('tenant', $tenant);
        $launch = new LtiLaunch(['id' => 'admin-preview', 'can_author' => true, 'lti_identity_id' => $content->lti_identity_id]);
        $launch->setRelation('deployment', $deployment);

        return new Runtime($launch, $content->exists ? route('admin.tenants.files', [$tenant, $content]) : null);
    }

    public function export(Tenant $tenant, H5pContent $content): BinaryFileResponse
    {
        return response()->download($this->runtime($tenant, $content)->export($content), $content->uuid.'.h5p')->deleteFileAfterSend(true);
    }

    public function preview(Tenant $tenant, H5pContent $content): View
    {
        $runtime = $this->runtime($tenant, $content);
        $filtered = $content->filtered ?? $runtime->filter($content);
        $dependencies = $runtime->framework->loadContentDependencies($content->id, 'preloaded');
        $assets = $runtime->core->getDependenciesFiles($dependencies);
        $scripts = array_merge(array_map(fn ($path) => route('h5p.assets', ['kind' => 'core', 'path' => $path]), \H5PCore::$scripts), $runtime->core->getAssetsUrls($assets['scripts']));
        $styles = array_merge(array_map(fn ($path) => route('h5p.assets', ['kind' => 'core', 'path' => $path]), \H5PCore::$styles), $runtime->core->getAssetsUrls($assets['styles']));
        $base = route('admin.tenants.files', [$tenant, $content]);
        $integration = ['baseUrl' => url('/'), 'siteUrl' => url('/'), 'url' => $base, 'urlLibraries' => $base.'/libraries', 'libraryUrl' => route('h5p.assets', ['kind' => 'core']), 'postUserStatistics' => false, 'saveFreq' => false, 'loadedJs' => [], 'loadedCss' => [], 'core' => ['scripts' => $scripts, 'styles' => $styles], 'l10n' => ['H5P' => $runtime->core->getLocalization()], 'contents' => ['cid-'.$content->id => [
            'library' => \H5PCore::libraryToString($content->library->definition()), 'jsonContent' => $filtered, 'fullScreen' => true,
            'scripts' => $runtime->core->getAssetsUrls($assets['scripts']), 'styles' => $runtime->core->getAssetsUrls($assets['styles']),
            'url' => route('admin.tenants.preview', [$tenant, $content]), 'contentUrl' => $base.'/content/'.$content->id,
            'metadata' => array_merge($content->metadata ?? [], ['title' => $content->title]),
            'displayOptions' => ['frame' => false, 'export' => false, 'embed' => false, 'copyright' => true, 'icon' => false, 'copy' => false],
        ]]];

        return view('admin.preview', compact('tenant', 'content', 'scripts', 'styles', 'integration'));
    }

    public function files(Tenant $tenant, H5pContent $content, string $path = ''): BinaryFileResponse
    {
        abort_unless($content->tenant_id === $tenant->id, 404);
        Runtime::safePath($path);
        if (str_starts_with($path, 'libraries/')) {
            return app(H5pController::class)->assets('libraries', substr($path, 10));
        }
        $prefix = 'content/'.$content->id.'/';
        abort_unless(str_starts_with($path, $prefix), 404);
        $relative = substr($path, strlen($prefix));
        Runtime::assertMediaPath($relative);
        $root = realpath(config('lti.storage_directory').'/tenants/'.$tenant->id.'/h5p/content/'.$content->id);
        $resolved = $root ? realpath($root.'/'.$relative) : false;
        abort_unless($root && $resolved && is_file($resolved) && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR), 404);

        return response()->file($resolved, ['X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => 'sandbox', 'Cache-Control' => 'private, no-store']);
    }
}
