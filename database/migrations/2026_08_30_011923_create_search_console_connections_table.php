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
        Schema::create('search_console_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('connected_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('property_site_url', 2048);
            $table->string('property_type', 24);
            $table->string('permission_level', 32);
            $table->text('access_token')->nullable();
            $table->text('refresh_token');
            $table->timestampTz('access_token_expires_at')->nullable();
            $table->string('status', 32)->default('connected')->index();
            $table->string('sync_batch_id')->nullable();
            $table->date('data_through')->nullable();
            $table->timestampTz('sync_started_at')->nullable();
            $table->timestampTz('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_console_connections');
    }
};
