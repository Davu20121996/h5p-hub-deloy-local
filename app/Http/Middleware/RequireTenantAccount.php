<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTenantAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()) {
            return redirect()->route('account.login');
        }
        abort_unless($request->user()->matchesAccountSession($request->session()->get('account_password')), 401, 'Vui lòng đăng nhập lại.');
        abort_unless($request->user()->canAccessWorkspace(), 403, 'Tài khoản hoặc kết nối đã bị khóa.');
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");

        return $response;
    }
}
