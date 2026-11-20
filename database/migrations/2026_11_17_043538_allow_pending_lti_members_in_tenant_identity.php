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
        Schema::table('tenant_identity', function (Blueprint $table): void {
            $table->timestamp('first_access_at')->nullable()->change();
            $table->timestamp('last_access_at')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('tenant_identity')->whereNull('first_access_at')
            ->update(['first_access_at' => now(), 'last_access_at' => now()]);

        Schema::table('tenant_identity', function (Blueprint $table): void {
            $table->timestamp('first_access_at')->nullable(false)->change();
            $table->timestamp('last_access_at')->nullable(false)->change();
        });
    }
};
