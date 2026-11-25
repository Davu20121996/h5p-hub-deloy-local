<?php

namespace App\Http\Middleware;

use App\Lti\Claims;
use App\Models\LtiLaunch;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLtiLaunch
{
    public function handle(Request $request, Closure $next): Response
    {
        $launch = $request->route('launch');
        abort_unless($launch instanceof LtiLaunch && $request->session()->get('lti_launches.'.$launch->id), 401,
            'Launch session unavailable. Reopen this activity from your website. If embedded cookies are blocked, open it in a new window.');
        $deployment = $launch->deployment;
        abort_unless($launch->expires_at->isFuture() && $deployment->active
            && $deployment->tenant->active && $deployment->platform->active, 403, 'Launch session expired or disabled.');
        $localRole = $launch->localRole();
        $launch->can_author = $localRole === null ? Claims::canAuthor($launch->roles) : in_array($localRole, ['teacher', 'tenant-admin'], true);
        $response = $next($request);
        $origins = array_filter($deployment->platform->allowed_origins, fn ($origin) => Claims::validUrl($origin));
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self' ".implode(' ', $origins));
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
