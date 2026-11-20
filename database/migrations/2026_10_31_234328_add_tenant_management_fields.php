<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 50)->nullable();
            $table->text('notes')->nullable();
        });
        Schema::table('h5p_contents', function (Blueprint $table) {
            $table->boolean('admin_hidden')->default(false);
        });
        Schema::create('tenant_identity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lti_identity_id')->constrained()->cascadeOnDelete();
            $table->json('roles');
            $table->timestamp('first_access_at');
            $table->timestamp('last_access_at');
            $table->unique(['tenant_id', 'lti_identity_id']);
        });
        DB::table('lti_launches')->join('lti_deployments', 'lti_deployments.id', '=', 'lti_launches.lti_deployment_id')
            ->select('lti_launches.*', 'lti_deployments.tenant_id')->orderBy('lti_launches.created_at')->orderBy('lti_launches.id')
            ->each(function (object $launch): void {
                $key = ['tenant_id' => $launch->tenant_id, 'lti_identity_id' => $launch->lti_identity_id];
                DB::table('tenant_identity')->insertOrIgnore($key + ['roles' => $launch->roles, 'first_access_at' => $launch->created_at, 'last_access_at' => $launch->created_at]);
                DB::table('tenant_identity')->where($key)->update(['roles' => $launch->roles, 'last_access_at' => $launch->created_at]);
            });
        DB::table('h5p_contents')->orderBy('id')->each(function (object $content): void {
            DB::table('tenant_identity')->insertOrIgnore(['tenant_id' => $content->tenant_id, 'lti_identity_id' => $content->lti_identity_id,
                'roles' => '[]', 'first_access_at' => $content->created_at, 'last_access_at' => $content->created_at]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_identity');
        Schema::table('h5p_contents', fn (Blueprint $table) => $table->dropColumn('admin_hidden'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['contact_name', 'contact_email', 'contact_phone', 'notes']));
    }
};
