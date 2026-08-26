<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $adminId = DB::table('users')
            ->where('is_admin', true)
            ->value('id');

        if ($adminId === null) {
            return;
        }

        DB::table('users')
            ->where('is_admin', false)
            ->whereNull('workspace_owner_id')
            ->update(['workspace_owner_id' => $adminId]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The backfill is intentionally not reversed because ownership is application data.
    }
};
