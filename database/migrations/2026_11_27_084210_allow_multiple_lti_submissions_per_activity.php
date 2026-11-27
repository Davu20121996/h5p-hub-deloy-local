<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('lti_submissions', function (Blueprint $table) {
            $table->uuid('attempt_id')->default('00000000-0000-4000-8000-000000000000');
        });
        Schema::table('lti_submissions', function (Blueprint $table) {
            $table->dropPrimary();
            $table->primary(['lti_resource_link_id', 'lti_identity_id', 'attempt_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('lti_submissions')->groupBy('lti_resource_link_id', 'lti_identity_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Cannot remove attempt IDs while multiple submissions exist.');
        }

        Schema::table('lti_submissions', function (Blueprint $table) {
            $table->dropPrimary();
            $table->primary(['lti_resource_link_id', 'lti_identity_id']);
        });
        Schema::table('lti_submissions', function (Blueprint $table) {
            $table->dropColumn('attempt_id');
        });
    }
};
