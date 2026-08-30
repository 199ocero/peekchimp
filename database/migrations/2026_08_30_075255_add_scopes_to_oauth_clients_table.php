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
        if (! Schema::hasColumn('oauth_clients', 'scopes')) {
            Schema::table('oauth_clients', function (Blueprint $table): void {
                $table->text('scopes')->default('[]');
            });
        }

        DB::table('oauth_clients')->whereNull('scopes')->update(['scopes' => '[]']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('oauth_clients', 'scopes')) {
            Schema::table('oauth_clients', function (Blueprint $table): void {
                $table->dropColumn('scopes');
            });
        }
    }
};
