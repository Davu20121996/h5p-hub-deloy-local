<?php

namespace App\Models;

use App\Lti\Claims;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class LtiLaunch extends Model
{
    use HasFactory;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['roles' => 'array', 'deep_linking_settings' => 'encrypted:array', 'can_author' => 'boolean', 'expires_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(LtiDeployment::class, 'lti_deployment_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(LtiIdentity::class, 'lti_identity_id');
    }

    public function localRole(): ?string
    {
        return DB::table('tenant_identity')->where('tenant_id', $this->deployment->tenant_id)
            ->where('lti_identity_id', $this->lti_identity_id)->value('local_role');
    }

    public function canManageTenant(): bool
    {
        $localRole = $this->localRole();
        if (Claims::isManagedClient($this->deployment->platform->issuer)) {
            return $this->can_author && in_array($localRole, [null, 'tenant-admin'], true)
                && in_array(Claims::ADMINISTRATOR, $this->roles, true);
        }

        return $localRole === 'tenant-admin';
    }

    public function canReportResult(string $uuid): bool
    {
        return ! $this->can_author && $this->message_type === 'LtiResourceLinkRequest'
            && $this->content_uuid === $uuid && $this->ags_lineitem_url !== null
            && in_array(Claims::LEARNER, $this->roles, true) && $this->deployment->platform->ags_token_url !== null;
    }

    public function content(string $uuid): H5pContent
    {
        return H5pContent::query()->where('tenant_id', $this->deployment->tenant_id)->where('admin_hidden', false)->where('uuid', $uuid)->firstOrFail();
    }

    public function authorizeEdit(H5pContent $content): void
    {
        abort_unless($this->can_author && $content->tenant_id === $this->deployment->tenant_id && $content->lti_identity_id === $this->lti_identity_id, 403);
    }
}
