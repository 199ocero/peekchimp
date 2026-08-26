<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insight_action_attempts', function (Blueprint $table): void {
            $table->string('outcome', 32)->nullable()->after('status');
            $table->index(['outcome', 'acted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('insight_action_attempts', function (Blueprint $table): void {
            $table->dropIndex(['outcome', 'acted_at']);
            $table->dropColumn('outcome');
        });
    }
};
