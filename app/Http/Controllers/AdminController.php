<?php

namespace App\Http\Controllers;

use App\H5p\LibraryInstaller;
use App\Lti\KeyStore;
use App\Models\H5pContent;
use App\Models\H5pLibrary;
use App\Models\LtiPlatform;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function login(): RedirectResponse
    {
        return redirect()->route('account.login');
    }

    public function index(): View
    {
        return view('admin.index', [
            'tenantCount' => Tenant::count(), 'contentCount' => H5pContent::count(),
            'platforms' => LtiPlatform::orderBy('name')->paginate(15, ['*'], 'platform_page'),
            'libraries' => H5pLibrary::orderBy('machine_name')->paginate(20, ['*'], 'library_page'),
        ]);
    }

    public function generateKeys(KeyStore $keys): RedirectResponse
    {
        Cache::lock('lti:key-generation', 60)->block(10, function () use ($keys): void {
            if ($keys->publicKey() === null) {
                $keys->generate();
            }
        });

        return back()->with('status', 'Khóa RSA đã sẵn sàng. Bạn có thể sao chép public key hoặc JWKS URL để cấu hình LMS.');
    }

    public function register(Request $request): RedirectResponse
    {
        $request->validate(['registration' => 'required|file|max:1024|extensions:json']);
        $status = Artisan::call('lti:register', ['file' => $request->file('registration')->getRealPath()]);
        if ($status !== 0) {
            return back()->withErrors(['registration' => Artisan::output()]);
        }

        return back()->with('status', 'Đã lưu kết nối LTI.');
    }

    public function install(Request $request, LibraryInstaller $installer): RedirectResponse
    {
        $request->validate(['package' => 'required|file|max:51200|extensions:h5p']);
        try {
            $count = $installer->install($request->file('package')->getRealPath());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['package' => 'Không thể cài thư viện. Kiểm tra gói H5P và các thư viện phụ thuộc.']);
        }

        return back()->with('status', "Đã cài hoặc cập nhật {$count} thư viện.");
    }
}
