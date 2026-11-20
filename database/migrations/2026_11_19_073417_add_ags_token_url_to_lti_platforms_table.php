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
        Schema::table('lti_platforms', function (Blueprint $table): void {
            $table->string('ags_token_url', 2048)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lti_platforms', function (Blueprint $table): void {
            $table->dropColumn('ags_token_url');
        });
    }
};
