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
        Schema::table('projects', function (Blueprint $table): void {
            $table->text('public_share_token')->nullable();
            $table->string('public_share_token_hash', 64)->nullable()->unique();
            $table->timestamp('public_share_enabled_at')->nullable()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->dropUnique(['public_share_token_hash']);
            $table->dropIndex(['public_share_enabled_at']);
            $table->dropColumn([
                'public_share_token',
                'public_share_token_hash',
                'public_share_enabled_at',
            ]);
        });
    }
};
