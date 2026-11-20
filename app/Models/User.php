<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(LtiDeployment::class, 'lti_deployment_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(LtiIdentity::class, 'lti_identity_id');
    }

    public function matchesAccountSession(?string $passwordHash): bool
    {
        return $passwordHash !== null && hash_equals(hash('sha256', $this->getAuthPassword()), $passwordHash);
    }

    public function canAccessWorkspace(): bool
    {
        return ! $this->is_admin && $this->active && in_array($this->role, ['tenant-admin', 'teacher'], true)
            && $this->tenant?->active && $this->deployment?->active && $this->deployment->platform->active
            && $this->deployment->tenant_id === $this->tenant_id
            && $this->identity?->lti_platform_id === $this->deployment->lti_platform_id;
    }
}
