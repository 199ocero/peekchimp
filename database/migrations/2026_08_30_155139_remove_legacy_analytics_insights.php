<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::dropIfExists('insight_action_attempts');
        Schema::dropIfExists('ai_insight_runs');
        Schema::dropIfExists('insights');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        throw new LogicException('Removing legacy analytics insights is irreversible. Restore the dropped data from a backup before rolling back this migration.');
    }
};
