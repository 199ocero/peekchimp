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
        Schema::create('search_console_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('report_date');
            $table->string('search_type', 16)->default('web');
            $table->string('dimension_type', 16);
            $table->text('dimension_value')->default('');
            $table->char('dimension_hash', 40);
            $table->string('normalized_path', 2048)->nullable();
            $table->unsignedBigInteger('clicks')->default(0);
            $table->unsignedBigInteger('impressions')->default(0);
            $table->decimal('position', 12, 4)->nullable();
            $table->timestamps();

            $table->unique(
                ['project_id', 'report_date', 'search_type', 'dimension_type', 'dimension_hash'],
                'search_console_metrics_unique',
            );
            $table->index(['project_id', 'dimension_type', 'report_date'], 'search_console_metrics_report_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('search_console_metrics');
    }
};
