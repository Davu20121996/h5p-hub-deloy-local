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
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lti_deployment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lti_identity_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role', 20)->nullable();
            $table->boolean('active')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('tenant_id');
            $table->dropConstrainedForeignId('lti_deployment_id');
            $table->dropUnique(['lti_identity_id']);
            $table->dropConstrainedForeignId('lti_identity_id');
            $table->dropColumn(['role', 'active']);
        });
    }
};
