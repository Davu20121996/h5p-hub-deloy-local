<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        Schema::create('lti_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('issuer', 512);
            $table->string('client_id');
            $table->text('authorization_url');
            $table->text('jwks_url');
            $table->json('allowed_origins');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['issuer', 'client_id']);
        });
        Schema::create('lti_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lti_platform_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('deployment_id');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['lti_platform_id', 'deployment_id']);
        });
        Schema::create('lti_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lti_platform_id')->constrained()->cascadeOnDelete();
            $table->string('subject');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
            $table->unique(['lti_platform_id', 'subject']);
        });
        Schema::create('lti_logins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lti_platform_id')->constrained()->cascadeOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->string('nonce_hash', 64);
            $table->text('target_link_uri');
            $table->string('deployment_hint')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('lti_launches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('lti_deployment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lti_identity_id')->constrained()->cascadeOnDelete();
            $table->string('message_type');
            $table->string('context_id')->nullable();
            $table->string('resource_link_id')->nullable();
            $table->string('content_uuid')->nullable();
            $table->boolean('can_author')->default(false);
            $table->json('roles');
            $table->text('deep_linking_settings')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('h5p_libraries', function (Blueprint $table) {
            $table->id();
            $table->string('machine_name');
            $table->unsignedInteger('major_version');
            $table->unsignedInteger('minor_version');
            $table->unsignedInteger('patch_version');
            $table->boolean('runnable')->default(false);
            $table->json('data');
            $table->timestamps();
            $table->unique(['machine_name', 'major_version', 'minor_version']);
        });
        Schema::create('h5p_contents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lti_identity_id')->constrained();
            $table->foreignId('h5p_library_id')->constrained();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->json('parameters');
            $table->json('metadata')->nullable();
            $table->text('filtered')->nullable();
            $table->json('dependencies')->nullable();
            $table->timestamps();
        });
        Schema::create('lti_resource_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lti_deployment_id')->constrained()->cascadeOnDelete();
            $table->string('resource_link_id');
            $table->string('context_id')->nullable();
            $table->foreignId('h5p_content_id')->constrained();
            $table->timestamps();
            $table->unique(['lti_deployment_id', 'resource_link_id']);
        });
        Schema::create('h5p_attempts', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->foreignUuid('lti_launch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('h5p_content_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 12, 4)->nullable();
            $table->decimal('max_score', 12, 4)->nullable();
            $table->boolean('completed')->default(false);
            $table->json('result');
            $table->timestamps();
            $table->unique(['lti_launch_id', 'event_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['h5p_attempts', 'lti_resource_links', 'h5p_contents', 'h5p_libraries', 'lti_launches', 'lti_logins', 'lti_identities', 'lti_deployments', 'lti_platforms', 'tenants'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
