<?php

namespace App\Http\Controllers;

use App\Lti\KeyStore;
use App\Models\LtiIdentity;
use App\Models\LtiLaunch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class TenantWorkspaceController extends Controller
{
    public function index(LtiLaunch $launch, KeyStore $keys): View
    {
        abort_unless($launch->canManageTenant(), 403);
        $members = DB::table('tenant_identity')->join('lti_identities', 'lti_identities.id', '=', 'tenant_identity.lti_identity_id')
            ->where('tenant_id', $launch->deployment->tenant_id)
            ->select('tenant_identity.*', 'lti_identities.name', 'lti_identities.subject', 'lti_identities.email')
            ->orderBy('lti_identities.name')->paginate(20);

        return view('workspace.tenant', [
            'launch' => $launch, 'members' => $members, 'publicKey' => $keys->publicKey(),
        ]);
    }

    public function role(Request $request, LtiLaunch $launch, LtiIdentity $identity): RedirectResponse
    {
        abort_unless($launch->canManageTenant(), 403);
        $data = $request->validate(['local_role' => 'required|in:teacher,learner']);
        $membership = DB::table('tenant_identity')->where('tenant_id', $launch->deployment->tenant_id)
            ->where('lti_identity_id', $identity->id);
        abort_unless($membership->exists(), 404);
        abort_if($identity->id === $launch->lti_identity_id || $membership->value('local_role') === 'tenant-admin', 403);
        $membership->update(['local_role' => $data['local_role']]);

        return back()->with('status', 'Đã cập nhật quyền giáo viên.');
    }
}
