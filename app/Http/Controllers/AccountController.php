<?php

namespace App\Http\Controllers;

use App\Lti\KeyStore;
use App\Models\H5pContent;
use App\Models\LtiDeployment;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function login(): View
    {
        return view('workspace.account-login');
    }

    public function authenticate(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:100', 'regex:/\A[A-Za-z0-9_.@+\-]+\z/'], 'password' => 'required|string|max:255']);
        if (! Auth::attempt($data + ['active' => true])) {
            return back()->withErrors(['username' => 'Tên đăng nhập hoặc mật khẩu không đúng.'])->withInput($request->only('username'));
        }
        if (! $request->user()->is_admin && ! $request->user()->canAccessWorkspace()) {
            Auth::logout();

            return back()->withErrors(['username' => 'Tài khoản hoặc kết nối của đơn vị đã bị khóa.'])->withInput($request->only('username'));
        }
        $request->session()->regenerate();
        $request->session()->put('account_password', hash('sha256', $request->user()->getAuthPassword()));

        return redirect()->route($request->user()->is_admin ? 'admin.index' : 'account.index');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('account.login');
    }

    public function index(Request $request, KeyStore $keys): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->role === 'teacher') {
            return redirect()->route('account.workspace');
        }

        $request->validate(['tab' => 'nullable|in:profile,connections,teachers,contents']);

        return view('workspace.accounts', [
            'tenant' => $user->tenant,
            'deployments' => LtiDeployment::with('platform')->where('tenant_id', $user->tenant_id)->get(),
            'contents' => H5pContent::with('library')->where('tenant_id', $user->tenant_id)->latest()->paginate(20, ['*'], 'contents_page')->withQueryString(),
            'accounts' => User::where('tenant_id', $user->tenant_id)->where('role', 'teacher')->orderBy('name')->paginate(20),
            'publicKey' => $keys->publicKey(),
        ]);
    }

    public function workspace(Request $request): RedirectResponse
    {
        return redirect()->route('workspace.index', $this->workspaceLaunch($request));
    }

    private function workspaceLaunch(Request $request): LtiLaunch
    {
        $user = $request->user();
        $launch = LtiLaunch::find($request->session()->get('account_launch'));
        if (! $launch || ! $launch->expires_at->isFuture() || $launch->lti_identity_id !== $user->lti_identity_id
            || $launch->lti_deployment_id !== $user->lti_deployment_id || $launch->message_type !== 'LocalWorkspace') {
            $launch = LtiLaunch::create([
                'id' => (string) Str::uuid(), 'lti_deployment_id' => $user->lti_deployment_id,
                'lti_identity_id' => $user->lti_identity_id, 'message_type' => 'LocalWorkspace',
                'roles' => [], 'can_author' => true, 'expires_at' => now()->addSeconds(config('lti.launch_lifetime')),
            ]);
            $request->session()->put('account_launch', $launch->id);
        }
        $request->session()->put('lti_launches.'.$launch->id, true);

        return $launch;
    }

    public function updateTenant(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'tenant-admin', 403);
        $request->user()->tenant->update($request->validate([
            'name' => $this->plainTextRules(), 'contact_name' => $this->plainTextRules(false),
            'contact_email' => 'nullable|email|max:255', 'contact_phone' => ['nullable', 'string', 'max:50', 'regex:/\A[0-9+().\s-]+\z/'],
            'notes' => $this->plainTextRules(false, 10000),
        ]));

        return back()->with('status', 'Đã cập nhật hồ sơ đơn vị.');
    }

    public function updateContent(Request $request, H5pContent $content): RedirectResponse
    {
        abort_unless($request->user()->role === 'tenant-admin', 403);
        abort_unless($content->tenant_id === $request->user()->tenant_id, 404);
        $content->update($request->validate(['status' => 'required|in:draft,published', 'admin_hidden' => 'required|boolean']));

        return back()->with('status', 'Đã cập nhật bài H5P của đơn vị.');
    }

    public function previewContent(Request $request, H5pContent $content): RedirectResponse
    {
        abort_unless($request->user()->role === 'tenant-admin', 403);
        abort_unless($content->tenant_id === $request->user()->tenant_id, 404);
        abort_if($content->admin_hidden, 403);

        return redirect()->route('content.play', [$this->workspaceLaunch($request), $content->uuid]);
    }

    public function storeAdmin(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->active, 403);
        $data = $request->validate(['deployment_id' => 'required|integer']);
        $deployment = LtiDeployment::where('tenant_id', $tenant->id)->where('active', true)
            ->whereHas('platform', fn ($query) => $query->where('active', true))->findOrFail($data['deployment_id']);
        $this->createAccount($request, $deployment, 'tenant-admin');

        return redirect()->route('admin.tenants.show', [$tenant, 'tab' => 'accounts'])->with('status', 'Đã cấp tài khoản tenant-admin. Gửi tên đăng nhập và mật khẩu cho người nhận.');
    }

    public function storeTeacher(Request $request): RedirectResponse
    {
        abort_unless($request->user()->role === 'tenant-admin', 403);
        $this->createAccount($request, $request->user()->deployment, 'teacher');

        return redirect()->route('account.index')->with('status', 'Đã cấp tài khoản giáo viên. Gửi tên đăng nhập và mật khẩu cho giáo viên.');
    }

    private function createAccount(Request $request, LtiDeployment $deployment, string $role): void
    {
        $data = $request->validate([
            'name' => $this->plainTextRules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'username' => ['required', 'string', 'regex:/\A[A-Za-z0-9_.@+\-]+\z/', 'max:100', Rule::unique('users')],
            'password' => 'required|string|min:12|max:255|confirmed',
        ], [
            'username.regex' => 'Tên đăng nhập có thể là email hoặc chứa chữ không dấu, số và các dấu . @ + - _. Không dùng khoảng trắng.',
        ]);
        DB::transaction(function () use ($request, $deployment, $role, $data): void {
            $identity = LtiIdentity::create([
                'lti_platform_id' => $deployment->lti_platform_id, 'subject' => 'hub-account:'.Str::uuid(),
                'name' => $data['name'], 'email' => $data['email'],
            ]);
            $user = new User(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password']]);
            $user->forceFill([
                'username' => $data['username'], 'tenant_id' => $deployment->tenant_id,
                'lti_deployment_id' => $deployment->id, 'lti_identity_id' => $identity->id,
                'role' => $role, 'active' => true, 'is_admin' => false, 'created_by' => $request->user()->id,
            ])->save();
        });
    }

    public function updateAdmin(Request $request, Tenant $tenant, User $account): RedirectResponse
    {
        abort_unless($account->tenant_id === $tenant->id && $account->role === 'tenant-admin' && ! $account->is_admin, 404);
        $this->updateAccount($request, $account);

        return back()->with('status', 'Đã cập nhật tài khoản tenant-admin.');
    }

    public function updateTeacher(Request $request, User $account): RedirectResponse
    {
        abort_unless($request->user()->role === 'tenant-admin', 403);
        abort_unless($account->tenant_id === $request->user()->tenant_id && $account->role === 'teacher' && ! $account->is_admin, 404);
        $this->updateAccount($request, $account);

        return back()->with('status', 'Đã cập nhật tài khoản giáo viên.');
    }

    private function updateAccount(Request $request, User $account): void
    {
        $data = $request->validate(['active' => 'required|boolean', 'password' => 'nullable|string|min:12|max:255|confirmed']);
        DB::transaction(function () use ($account, $data): void {
            $account->active = (bool) $data['active'];
            if (! empty($data['password'])) {
                $account->password = $data['password'];
            }
            $account->save();
            if (! $account->active || ! empty($data['password'])) {
                LtiLaunch::where('lti_identity_id', $account->lti_identity_id)->where('message_type', 'LocalWorkspace')->update(['expires_at' => now()]);
            }
        });
    }
}
