<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('h5p_attempts');

        if (Schema::hasColumn('lti_launches', 'ags_lineitem_url')) {
            Schema::table('lti_launches', function (Blueprint $table): void {
                $table->dropColumn('ags_lineitem_url');
            });
        }

        if (Schema::hasColumn('lti_platforms', 'ags_token_url')) {
            Schema::table('lti_platforms', function (Blueprint $table): void {
                $table->dropColumn('ags_token_url');
            });
        }

        if (Schema::hasColumn('users', 'tenant_id')) {
            $tenantAccountIds = DB::table('users')->where('is_admin', false)->select('id');

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $tenantAccountIds)->delete();
            }

            DB::table('users')->where('is_admin', false)->delete();

            Schema::table('users', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('created_by');
                $table->dropConstrainedForeignId('tenant_id');
                $table->dropConstrainedForeignId('lti_deployment_id');
                $table->dropUnique(['lti_identity_id']);
                $table->dropConstrainedForeignId('lti_identity_id');
                $table->dropColumn('role');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lti_deployment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lti_identity_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20)->nullable();
        });

        Schema::table('lti_platforms', function (Blueprint $table): void {
            $table->string('ags_token_url', 2048)->nullable();
        });

        Schema::table('lti_launches', function (Blueprint $table): void {
            $table->text('ags_lineitem_url')->nullable();
        });

        Schema::create('h5p_attempts', function (Blueprint $table): void {
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
};
