<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lti_platforms', function (Blueprint $table): void {
            $table->text('ags_token_url')->nullable();
        });

        Schema::table('lti_launches', function (Blueprint $table): void {
            $table->text('ags_lineitem_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lti_launches', function (Blueprint $table): void {
            $table->dropColumn('ags_lineitem_url');
        });

        Schema::table('lti_platforms', function (Blueprint $table): void {
            $table->dropColumn('ags_token_url');
        });
    }
};
