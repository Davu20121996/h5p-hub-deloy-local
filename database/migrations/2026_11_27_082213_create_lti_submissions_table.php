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
        Schema::create('lti_submissions', function (Blueprint $table) {
            $table->foreignId('lti_resource_link_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lti_identity_id')->constrained()->cascadeOnDelete();
            $table->text('pending_result')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->primary(['lti_resource_link_id', 'lti_identity_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lti_submissions');
    }
};
